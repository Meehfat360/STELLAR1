<?php
defined( 'ABSPATH' ) || exit;

/**
 * Enterprise Conversions API (CAPI) engine.
 * Sends events server-side to Meta. All events are queued first,
 * then dispatched by the background processor with retry support.
 */
class Stellar_Meta_CAPI {

    const API_URL = 'https://graph.facebook.com/v19.0/%s/events';

    private static ?self $instance = null;
    private Stellar_Meta_Settings $settings;
    private Stellar_Meta_Event_Builder $builder;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->settings = Stellar_Meta_Settings::instance();
        $this->builder  = new Stellar_Meta_Event_Builder();
        $this->hooks();
    }

    private function hooks(): void {
        // WooCommerce order events
        add_action( 'woocommerce_thankyou',               [ $this, 'on_purchase' ], 10, 1 );
        add_action( 'woocommerce_checkout_order_created', [ $this, 'on_purchase' ], 10, 1 );
        add_action( 'woocommerce_add_to_cart',            [ $this, 'on_add_to_cart' ], 10, 6 );
    }

    // ── Event Triggers ────────────────────────────────────────────────────────

    public function on_purchase( $order_id ): void {
        if ( ! $this->settings->is_capi_enabled() ) return;
        if ( ! $order_id ) return;

        // Prevent duplicate purchase events (e.g. thank-you page + webhook)
        $fired_key = '_stellar_capi_purchase_fired';
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( $order->get_meta( $fired_key ) ) return;

        $payload   = $this->builder->purchase( $order );
        $user_data = $this->builder->user_data_from_order( $order );
        $event_id  = $this->builder->generate_event_id( 'Purchase', $order_id );

        $this->queue_event( 'Purchase', $payload, $user_data, $event_id );
        $order->update_meta_data( $fired_key, time() );
        $order->save();
    }

    public function on_add_to_cart( string $cart_item_key, int $product_id, int $quantity, ...$args ): void {
        if ( ! $this->settings->is_capi_enabled() ) return;
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $payload   = $this->builder->add_to_cart( $product, $quantity );
        $user_data = $this->builder->user_data_from_current_user();
        $event_id  = $this->builder->generate_event_id( 'AddToCart' );

        $this->queue_event( 'AddToCart', $payload, $user_data, $event_id );
    }

    /**
     * Send a ViewContent event from a product page (called via REST endpoint).
     */
    public function send_view_content( int $product_id, array $client_data = [] ): void {
        if ( ! $this->settings->is_capi_enabled() ) return;
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $payload             = $this->builder->view_content( $product );
        $user_data           = $this->builder->user_data_from_current_user();
        $user_data           = array_merge( $user_data, $client_data );
        $event_id            = $client_data['event_id'] ?? $this->builder->generate_event_id( 'ViewContent' );

        $this->queue_event( 'ViewContent', $payload, $user_data, $event_id );
    }

    /**
     * Send InitiateCheckout from checkout page (called via REST endpoint).
     */
    public function send_initiate_checkout( array $client_data = [] ): void {
        if ( ! $this->settings->is_capi_enabled() ) return;
        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) return;

        $payload   = $this->builder->initiate_checkout( $cart );
        $user_data = $this->builder->user_data_from_current_user();
        $user_data = array_merge( $user_data, $client_data );
        $event_id  = $client_data['event_id'] ?? $this->builder->generate_event_id( 'InitiateCheckout' );

        $this->queue_event( 'InitiateCheckout', $payload, $user_data, $event_id );
    }

    // ── Queue ─────────────────────────────────────────────────────────────────

    private function queue_event( string $event_name, array $payload, array $user_data, string $event_id ): void {
        // Check deduplication before queuing
        if ( Stellar_Meta_Deduplicator::is_duplicate( $event_id ) ) {
            Stellar_Meta_Logger::info( 'CAPI: duplicate skipped', [ 'event_id' => $event_id ] );
            return;
        }

        $full_payload = array_merge( $payload, [
            'user_data' => $user_data,
            'event_id'  => $event_id,
            'event_time'=> time(),
            'action_source' => 'website',
        ] );

        global $wpdb;
        $wpdb->insert(
            STELLAR_META_DB_PREFIX . 'event_queue',
            [
                'event_id'    => $event_id,
                'event_name'  => $event_name,
                'payload'     => wp_json_encode( $full_payload ),
                'platform'    => 'meta',
                'status'      => 'pending',
                'attempts'    => 0,
                'max_attempts'=> $this->settings->capi_max_retries(),
                'scheduled_at'=> current_time( 'mysql' ),
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );
    }

    // ── Dispatcher ────────────────────────────────────────────────────────────

    /**
     * Send a queued event to Meta CAPI.
     * Called by Stellar_Meta_Event_Queue processor.
     *
     * @return array{success:bool, http_code:int, latency_ms:int, message:string}
     */
    public function dispatch( array $queue_row ): array {
        $pixel_id     = $this->settings->pixel_id();
        $access_token = $this->settings->access_token();

        if ( ! $pixel_id || ! $access_token ) {
            return [ 'success' => false, 'http_code' => 0, 'latency_ms' => 0, 'message' => 'Missing credentials' ];
        }

        $payload = json_decode( $queue_row['payload'], true );

        $body = [
            'data'         => [ $this->normalize_for_api( $payload ) ],
            'access_token' => $access_token,
        ];

        // Append test event code if set
        if ( $code = $this->settings->test_event_code() ) {
            $body['test_event_code'] = $code;
        }

        $start = microtime( true );
        $url   = sprintf( self::API_URL, $pixel_id );

        try {
            $response = wp_remote_post( $url, [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $body ),
                'timeout' => 15,
            ] );
        } catch ( \Throwable $e ) {
            return [
                'success'    => false,
                'http_code'  => 0,
                'latency_ms' => (int) round( ( microtime(true) - $start ) * 1000 ),
                'message'    => $e->getMessage(),
            ];
        }

        $latency = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return [
                'success'    => false,
                'http_code'  => 0,
                'latency_ms' => $latency,
                'message'    => $response->get_error_message(),
            ];
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $body_r  = json_decode( wp_remote_retrieve_body( $response ), true );
        $success = $code >= 200 && $code < 300;

        if ( $success ) {
            Stellar_Meta_Logger::info( 'CAPI: delivered', [
                'event_id'   => $payload['event_id'] ?? '',
                'event_name' => $queue_row['event_name'],
                'latency_ms' => $latency,
            ] );
        } else {
            Stellar_Meta_Logger::error( 'CAPI: failed', [
                'event_id'   => $payload['event_id'] ?? '',
                'event_name' => $queue_row['event_name'],
                'http_code'  => $code,
                'response'   => $body_r,
            ] );
        }

        return [
            'success'    => $success,
            'http_code'  => $code,
            'latency_ms' => $latency,
            'message'    => $success ? 'delivered' : ( $body_r['error']['message'] ?? 'API error' ),
        ];
    }

    private function normalize_for_api( array $payload ): array {
        return [
            'event_name'    => $payload['event_name'],
            'event_time'    => $payload['event_time'],
            'event_id'      => $payload['event_id'],
            'action_source' => $payload['action_source'] ?? 'website',
            'user_data'     => $payload['user_data'] ?? [],
            'custom_data'   => $payload['custom_data'] ?? [],
        ];
    }
}

// ── Checkout: save GA4 client_id + gclid from form ───────────────────────────
add_action( 'woocommerce_checkout_order_created', function( WC_Order $order ): void {
    if ( ! empty( $_POST['_stellar_ga4_client_id'] ) ) {
        $order->update_meta_data( '_stellar_ga4_client_id', sanitize_text_field( wp_unslash( $_POST['_stellar_ga4_client_id'] ) ) );
    }
    if ( ! empty( $_POST['_stellar_gclid'] ) ) {
        $order->update_meta_data( '_stellar_gclid', sanitize_text_field( wp_unslash( $_POST['_stellar_gclid'] ) ) );
    }
    if ( ! empty( $_COOKIE['_gcl_aw'] ) ) {
        $order->update_meta_data( '_stellar_gclid', sanitize_text_field( explode('.', wp_unslash($_COOKIE['_gcl_aw'] ?? ''))[2] ?? '' ) );
    }
    $order->save();
} );
