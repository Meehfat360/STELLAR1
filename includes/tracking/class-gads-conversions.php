<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Ads Enhanced Conversions — server-side.
 * Sends purchase events with hashed customer data directly to Google Ads API.
 * Improves attribution accuracy by 10-20% per Google benchmarks.
 */
class Stellar_Meta_GAds_Conversions {

    const GADS_API_URL = 'https://googleads.googleapis.com/v14/customers/%s:uploadClickConversions';

    private static ?self $instance = null;
    private Stellar_Meta_Settings $settings;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->settings = Stellar_Meta_Settings::instance();
        if ( ! $this->settings->is_gads_enhanced_enabled() ) return;
        $this->hooks();
    }

    private function hooks(): void {
        add_action( 'woocommerce_thankyou',               [ $this, 'on_purchase' ], 20 );
        add_action( 'woocommerce_checkout_order_created', [ $this, 'on_purchase' ], 20 );
    }

    public function on_purchase( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( $order->get_meta( '_stellar_gads_conv_fired' ) ) return;

        $hash   = fn( string $v ): string => hash( 'sha256', strtolower( trim( $v ) ) );
        $gclid  = $order->get_meta( '_stellar_gclid' ) ?: ( sanitize_text_field( $_COOKIE['_gcl_aw'] ?? '' ) );
        if ( ! $gclid ) {
            // No click ID — still log but skip API call
            $order->update_meta_data( '_stellar_gads_conv_fired', 'no_gclid' );
            $order->save();
            return;
        }

        $user_identifiers = [];
        if ( $email = $order->get_billing_email() )
            $user_identifiers['hashedEmail'] = $hash( $email );
        if ( $phone = preg_replace( '/\D/', '', $order->get_billing_phone() ) )
            $user_identifiers['hashedPhoneNumber'] = $hash( $phone );

        $payload = [
            'conversions' => [ [
                'gclid'                  => $gclid,
                'conversionAction'       => 'customers/' . $this->settings->google_ads_id() . '/conversionActions/' . $this->settings->gads_conversion_label(),
                'conversionDateTime'     => gmdate( 'Y-m-d H:i:sP', $order->get_date_created()->getTimestamp() ),
                'conversionValue'        => (float) $order->get_total(),
                'currencyCode'           => $order->get_currency(),
                'orderId'                => (string) $order_id,
                'userIdentifiers'        => [ $user_identifiers ],
            ] ],
            'partialFailure' => true,
        ];

        // Store payload in event queue to dispatch async with retry
        global $wpdb;
        $wpdb->insert( STELLAR_META_DB_PREFIX . 'event_queue', [
            'event_id'   => hash( 'sha256', 'gads_conv_' . $order_id ),
            'event_name' => 'GAdsPurchase',
            'payload'    => wp_json_encode( $payload ),
            'platform'   => 'google_ads',
            'status'     => 'pending',
        ], [ '%s','%s','%s','%s','%s' ] );

        $order->update_meta_data( '_stellar_gads_conv_fired', time() );
        $order->save();
        Stellar_Meta_Logger::info( 'GAds Enhanced Conv queued', [ 'order_id' => $order_id ] );
    }
}
