<?php
defined( 'ABSPATH' ) || exit;

/**
 * Builds normalized Meta event payloads from WooCommerce objects.
 * v2: Attaches LTV tier, bid score, and stellar_uid to all events.
 */
class Stellar_Meta_Event_Builder {

    private Stellar_Meta_Settings $settings;

    public function __construct() {
        $this->settings = Stellar_Meta_Settings::instance();
    }

    public function view_content( WC_Product $product ): array {
        $data = [ 'event_name' => 'ViewContent', 'custom_data' => [
            'content_ids'  => [ (string) $product->get_id() ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value'        => (float) $product->get_price(),
            'currency'     => get_woocommerce_currency(),
            'bid_signal'   => Stellar_Meta_Bid_Scorer::score_current_visitor(),
            'stellar_uid'  => Stellar_Meta_Identity_Graph::get_uid(),
        ] ];
        return $this->apply_custom_mappings( 'ViewContent', $data, [ 'product' => $product ] );
    }

    public function add_to_cart( WC_Product $product, int $quantity = 1 ): array {
        $data = [ 'event_name' => 'AddToCart', 'custom_data' => [
            'content_ids'  => [ (string) $product->get_id() ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value'        => round( (float) $product->get_price() * $quantity, 2 ),
            'currency'     => get_woocommerce_currency(),
            'num_items'    => $quantity,
            'bid_signal'   => Stellar_Meta_Bid_Scorer::score_current_visitor(),
            'stellar_uid'  => Stellar_Meta_Identity_Graph::get_uid(),
        ] ];
        return $this->apply_custom_mappings( 'AddToCart', $data, [ 'product' => $product ] );
    }

    public function initiate_checkout( WC_Cart $cart ): array {
        $ids  = array_values( array_map( fn($i) => (string) $i['product_id'], $cart->get_cart() ) );
        $data = [ 'event_name' => 'InitiateCheckout', 'custom_data' => [
            'content_ids'  => $ids,
            'content_type' => 'product',
            'num_items'    => $cart->get_cart_contents_count(),
            'value'        => round( (float) $cart->get_total('edit'), 2 ),
            'currency'     => get_woocommerce_currency(),
            'bid_signal'   => Stellar_Meta_Bid_Scorer::score_current_visitor(),
            'stellar_uid'  => Stellar_Meta_Identity_Graph::get_uid(),
        ] ];
        return $this->apply_custom_mappings( 'InitiateCheckout', $data, [ 'cart' => $cart ] );
    }

    public function purchase( WC_Order $order ): array {
        $ids  = array_values( array_map(
            fn( WC_Order_Item_Product $item ) => (string) $item->get_product_id(),
            array_filter( $order->get_items(), fn($i) => $i instanceof WC_Order_Item_Product )
        ) );
        $data = [ 'event_name' => 'Purchase', 'custom_data' => [
            'order_id'     => (string) $order->get_id(),
            'content_ids'  => $ids,
            'content_type' => 'product',
            'num_items'    => count($ids),
            'value'        => (float) $order->get_total(),
            'currency'     => $order->get_currency(),
            'order_status' => $order->get_status(),
            'stellar_uid'  => Stellar_Meta_Identity_Graph::get_uid(),
        ] ];

        // Attach LTV tier to purchase event
        if ( $order->get_customer_id() ) {
            $ltv = Stellar_Meta_LTV_Predictor::get_score( $order->get_customer_id() );
            if ( $ltv ) {
                $data['custom_data']['ltv_tier']        = $ltv['ltv_tier'];
                $data['custom_data']['predicted_12m']   = $ltv['predicted_12m'];
                // Use predicted LTV as the 'value' signal for Meta Value Optimization
                if ( in_array( $ltv['ltv_tier'], ['high','vip'], true ) ) {
                    $data['custom_data']['value'] = max( (float)$order->get_total(), (float)$ltv['predicted_90d'] );
                }
            }
        }

        if ( $this->settings->is_ai_enrichment_enabled() ) {
            $data = $this->enrich_with_ai( $data, $order );
        }

        return $this->apply_custom_mappings( 'Purchase', $data, [ 'order' => $order ] );
    }

    public function user_data_from_order( WC_Order $order ): array {
        $hash = fn(string $v): string => hash('sha256', strtolower(trim($v)));
        $ud   = [];
        if ($e  = $order->get_billing_email())                      $ud['em']      = $hash($e);
        if ($p  = preg_replace('/\D/','', $order->get_billing_phone())) $ud['ph'] = $hash($p);
        if ($fn = $order->get_billing_first_name())                 $ud['fn']      = $hash($fn);
        if ($ln = $order->get_billing_last_name())                  $ud['ln']      = $hash($ln);
        if ($zp = $order->get_billing_postcode())                   $ud['zp']      = $hash($zp);
        if ($ct = $order->get_billing_city())                       $ud['ct']      = $hash(strtolower(str_replace(' ','',$ct)));
        if ($co = $order->get_billing_country())                    $ud['country'] = $hash(strtolower($co));
        $ud['client_ip_address'] = $this->client_ip();
        $ud['client_user_agent'] = $this->user_agent();
        if (!empty($_COOKIE['_fbc'])) $ud['fbc'] = sanitize_text_field($_COOKIE['_fbc']);
        if (!empty($_COOKIE['_fbp'])) $ud['fbp'] = sanitize_text_field($_COOKIE['_fbp']);
        return $ud;
    }

    public function user_data_from_current_user(): array {
        $hash = fn(string $v): string => hash('sha256', strtolower(trim($v)));
        $ud   = [];
        $user = wp_get_current_user();
        if ($user->ID) { $ud['em']=$hash($user->user_email); $ud['fn']=$hash($user->first_name); $ud['ln']=$hash($user->last_name); }
        $ud['client_ip_address'] = $this->client_ip();
        $ud['client_user_agent'] = $this->user_agent();
        if (!empty($_COOKIE['_fbc'])) $ud['fbc'] = sanitize_text_field($_COOKIE['_fbc']);
        if (!empty($_COOKIE['_fbp'])) $ud['fbp'] = sanitize_text_field($_COOKIE['_fbp']);
        return $ud;
    }

    public function generate_event_id( string $event_name, ?int $order_id = null ): string {
        return $order_id ? hash('sha256', "{$event_name}_{$order_id}") : wp_generate_uuid4();
    }

    private function apply_custom_mappings( string $event, array $data, array $context ): array {
        $mappings = $this->settings->custom_event_mappings();
        if (empty($mappings[$event])) return $data;
        foreach ($mappings[$event] as $meta_key => $wc_key) {
            $resolved = $this->resolve_wc_value($wc_key, $context);
            if (null !== $resolved) $data['custom_data'][$meta_key] = $resolved;
        }
        return $data;
    }

    private function resolve_wc_value(string $key, array $ctx): mixed {
        return match($key) {
            'order_total'   => isset($ctx['order']) ? (float)$ctx['order']->get_total() : null,
            'shop_currency' => get_woocommerce_currency(),
            'item_count'    => isset($ctx['order']) ? count($ctx['order']->get_items()) : null,
            default         => null,
        };
    }

    private function enrich_with_ai( array $data, WC_Order $order ): array {
        global $wpdb;
        $ids = $data['custom_data']['content_ids'] ?? [];
        if (empty($ids)) return $data;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, purchase_type, price_tier, auto_tags FROM ".STELLAR_META_DB_PREFIX."ai_product_data WHERE product_id IN ({$placeholders})",
            ...$ids
        ));
        if (!$rows) return $data;
        $data['custom_data']['ai_purchase_types'] = array_unique(array_filter(array_column((array)$rows, 'purchase_type')));
        $data['custom_data']['ai_price_tiers']    = array_unique(array_filter(array_column((array)$rows, 'price_tier')));
        $data['custom_data']['ai_tags']           = array_unique(array_filter(array_column((array)$rows, 'auto_tags')));
        return $data;
    }

    private function client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) return sanitize_text_field(explode(',', $_SERVER[$k])[0]);
        }
        return '';
    }

    private function user_agent(): string {
        return sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
    }
}
