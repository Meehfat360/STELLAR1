<?php
defined( 'ABSPATH' ) || exit;

/**
 * GA4 Server-Side via Measurement Protocol v2.
 * Sends purchase/refund/add_to_cart events from PHP — bypasses iOS ITP,
 * ad blockers, and cookie restrictions entirely.
 */
class Stellar_Meta_GA4_Server {

    const MP_URL = 'https://www.google-analytics.com/mp/collect';

    private static ?self $instance = null;
    private Stellar_Meta_Settings $settings;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->settings = Stellar_Meta_Settings::instance();
        $this->hooks();
    }

    private function hooks(): void {
        add_action( 'woocommerce_thankyou',               [ $this, 'on_purchase' ], 15 );
        add_action( 'woocommerce_checkout_order_created', [ $this, 'on_purchase' ], 15 );
        add_action( 'woocommerce_order_status_refunded',  [ $this, 'on_refund'   ], 10 );
        add_action( 'woocommerce_add_to_cart',            [ $this, 'on_add_to_cart' ], 10, 2 );
    }

    // ── Events ────────────────────────────────────────────────────────────────

    public function on_purchase( $order_id ): void {
        if ( ! $this->is_enabled() ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_stellar_ga4_purchase_fired' ) ) return;

        $items     = $this->build_items( $order );
        $client_id = $this->get_client_id( $order );

        $this->send( $client_id, 'purchase', [
            'transaction_id' => (string) $order->get_id(),
            'affiliation'    => get_bloginfo('name'),
            'value'          => (float) $order->get_subtotal(),
            'tax'            => (float) $order->get_total_tax(),
            'shipping'       => (float) $order->get_shipping_total(),
            'currency'       => $order->get_currency(),
            'coupon'         => implode(',', $order->get_coupon_codes()),
            'items'          => $items,
        ] );

        $order->update_meta_data( '_stellar_ga4_purchase_fired', time() );
        $order->save();
    }

    public function on_refund( $order_id ): void {
        if ( ! $this->is_enabled() ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $this->send( $this->get_client_id( $order ), 'refund', [
            'transaction_id' => (string) $order->get_id(),
            'value'          => (float) $order->get_total(),
            'currency'       => $order->get_currency(),
        ] );
    }

    public function on_add_to_cart( string $cart_item_key, int $product_id ): void {
        if ( ! $this->is_enabled() ) return;
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $this->send( $this->get_ga4_client_id_from_cookie(), 'add_to_cart', [
            'currency' => get_woocommerce_currency(),
            'value'    => (float) $product->get_price(),
            'items'    => [ $this->product_to_item( $product, 1 ) ],
        ] );
    }

    // ── Core sender ───────────────────────────────────────────────────────────

    public function send( string $client_id, string $event_name, array $params ): bool {
        $measurement_id = $this->settings->ga4_measurement_id();
        $api_secret     = $this->settings->ga4_api_secret();
        if ( ! $measurement_id || ! $api_secret ) return false;

        $url  = add_query_arg( [
            'measurement_id' => $measurement_id,
            'api_secret'     => $api_secret,
        ], self::MP_URL );

        // Dedup: skip if browser event already fired for same transaction
        if ( isset( $params['transaction_id'] ) ) {
            $dedup_key = 'sm_ga4_' . md5( $event_name . $params['transaction_id'] );
            if ( get_transient( $dedup_key ) ) return false;
            set_transient( $dedup_key, 1, HOUR_IN_SECONDS * 2 );
        }

        $body = wp_json_encode( [
            'client_id'        => $client_id ?: wp_generate_uuid4(),
            'timestamp_micros' => (string) ( microtime(true) * 1_000_000 ),
            'events'           => [ [ 'name' => $event_name, 'params' => $params ] ],
        ] );

        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => $body,
            'timeout' => 5,
            'blocking'=> false, // fire-and-forget
        ] );

        Stellar_Meta_Logger::info( 'GA4 Server', [ 'event' => $event_name, 'client_id' => $client_id ] );
        return ! is_wp_error( $response );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function build_items( WC_Order $order ): array {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) continue;
            $product = $item->get_product();
            if ( ! $product ) continue;
            $items[] = $this->product_to_item( $product, $item->get_quantity(), $item->get_subtotal() / $item->get_quantity() );
        }
        return $items;
    }

    private function product_to_item( WC_Product $product, int $qty = 1, ?float $price = null ): array {
        $cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
        return [
            'item_id'       => (string) $product->get_id(),
            'item_name'     => $product->get_name(),
            'item_category' => ! empty( $cats ) ? $cats[0] : '',
            'item_brand'    => get_bloginfo('name'),
            'price'         => round( $price ?? (float) $product->get_price(), 2 ),
            'quantity'      => $qty,
            'currency'      => get_woocommerce_currency(),
        ];
    }

    private function get_client_id( WC_Order $order ): string {
        // Try order meta first (set at checkout)
        $cid = $order->get_meta( '_stellar_ga4_client_id' );
        if ( $cid ) return $cid;
        // Fallback to cookie
        return $this->get_ga4_client_id_from_cookie();
    }

    private function get_ga4_client_id_from_cookie(): string {
        if ( ! empty( $_COOKIE['_ga'] ) ) {
            $parts = explode( '.', sanitize_text_field( $_COOKIE['_ga'] ) );
            if ( count( $parts ) >= 4 ) return $parts[2] . '.' . $parts[3];
        }
        return wp_generate_uuid4();
    }

    private function is_enabled(): bool {
        return $this->settings->is_ga4_server_enabled()
            && $this->settings->ga4_measurement_id()
            && $this->settings->ga4_api_secret();
    }
}
