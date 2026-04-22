<?php
defined( 'ABSPATH' ) || exit;

/**
 * GA4 Enhanced Ecommerce — full item-level event schema.
 * Injects server-rendered gtag() calls for view_item_list, select_item,
 * add_to_wishlist, view_promotion, and fires refund server-side.
 */
class Stellar_Meta_GA4_EEC {

    private static ?self $instance = null;
    private Stellar_Meta_Settings $settings;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->settings = Stellar_Meta_Settings::instance();
        if ( ! $this->settings->is_ga4_eec_enabled() || ! $this->settings->ga4_measurement_id() ) return;
        $this->hooks();
    }

    private function hooks(): void {
        add_action( 'wp_footer', [ $this, 'inject_eec_events' ], 25 );
        // Server-side: refund event
        add_action( 'woocommerce_order_status_refunded', [ $this, 'server_refund' ], 10 );
        // Add to wishlist compatibility (YITH / WooCommerce Wishlists)
        add_action( 'yith_wcwl_added_to_wishlist', [ $this, 'on_add_to_wishlist' ], 10 );
    }

    public function inject_eec_events(): void {
        if ( ! $this->settings->ga4_measurement_id() ) return;

        $ctx = $this->get_page_context();
        if ( empty( $ctx ) ) return;

        $events = [];

        if ( is_shop() || is_product_category() ) {
            $events[] = $this->view_item_list_event( $ctx );
        }

        if ( is_product() && ! empty( $ctx['product'] ) ) {
            $events[] = $this->view_item_event( $ctx['product'] );
        }

        if ( empty( $events ) ) return;

        echo '<script>';
        foreach ( $events as $ev ) {
            printf( 'gtag("event","%s",%s);', esc_js( $ev['name'] ), wp_json_encode( $ev['params'] ) );
        }
        echo '</script>';
    }

    private function view_item_list_event( array $ctx ): array {
        $items = [];
        $i     = 0;
        foreach ( $ctx['products'] ?? [] as $product ) {
            $items[] = [
                'item_id'        => (string) $product->get_id(),
                'item_name'      => $product->get_name(),
                'item_list_name' => $ctx['list_name'] ?? 'Shop',
                'index'          => $i++,
                'price'          => (float) $product->get_price(),
                'currency'       => get_woocommerce_currency(),
            ];
        }
        return [ 'name' => 'view_item_list', 'params' => [ 'item_list_name' => $ctx['list_name'] ?? 'Shop', 'items' => $items ] ];
    }

    private function view_item_event( WC_Product $product ): array {
        $cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
        return [
            'name'   => 'view_item',
            'params' => [
                'currency' => get_woocommerce_currency(),
                'value'    => (float) $product->get_price(),
                'items'    => [ [
                    'item_id'       => (string) $product->get_id(),
                    'item_name'     => $product->get_name(),
                    'item_category' => ! empty( $cats ) ? $cats[0] : '',
                    'price'         => (float) $product->get_price(),
                    'quantity'      => 1,
                ] ],
            ],
        ];
    }

    public function on_add_to_wishlist( int $product_id ): void {
        // Queue a server-side add_to_wishlist event via GA4 MP
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;
        $ga4 = Stellar_Meta_GA4_Server::instance();
        $ga4->send( '', 'add_to_wishlist', [
            'currency' => get_woocommerce_currency(),
            'value'    => (float) $product->get_price(),
            'items'    => [ [ 'item_id' => (string) $product_id, 'item_name' => $product->get_name(), 'price' => (float) $product->get_price(), 'quantity' => 1 ] ],
        ] );
    }

    public function server_refund( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $items = [];
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) continue;
            $items[] = [ 'item_id' => (string) $item->get_product_id(), 'item_name' => $item->get_name(), 'price' => (float) ( $item->get_subtotal() / max( $item->get_quantity(), 1 ) ), 'quantity' => $item->get_quantity() ];
        }
        $ga4 = Stellar_Meta_GA4_Server::instance();
        $ga4->send( '', 'refund', [ 'transaction_id' => (string) $order_id, 'value' => (float) $order->get_total(), 'currency' => $order->get_currency(), 'items' => $items ] );
    }

    private function get_page_context(): array {
        $ctx = [];
        if ( is_shop() || is_product_category() ) {
            global $wp_query;
            $ctx['list_name'] = is_product_category() ? single_cat_title( '', false ) : 'Shop';
            $ctx['products']  = [];
            if ( $wp_query && $wp_query->posts ) {
                foreach ( $wp_query->posts as $post ) {
                    $p = wc_get_product( $post->ID );
                    if ( $p ) $ctx['products'][] = $p;
                }
            }
        }
        if ( is_product() ) {
            global $post;
            $ctx['product'] = wc_get_product( $post );
        }
        return $ctx;
    }
}
