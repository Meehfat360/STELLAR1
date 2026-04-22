<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Lifetime Value Prediction Engine.
 * Uses GPT-4o + order history to predict 90-day and 12-month LTV.
 * Feeds high-LTV signals to Meta Value Optimization bidding.
 */
class Stellar_Meta_LTV_Predictor {

    const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';
    const MODEL      = 'gpt-4o';

    private static Stellar_Meta_Settings $settings;

    // ── Batch runner (called by cron) ─────────────────────────────────────────

    public static function run_batch( int $limit = 50 ): void {
        self::$settings = Stellar_Meta_Settings::instance();
        if ( ! self::$settings->is_ltv_enabled() ) return;

        // Get customers with at least 1 order, not scored recently
        global $wpdb;
        $customers = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT p.post_author AS customer_id
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'shop_order'
               AND p.post_status IN ('wc-completed','wc-processing')
               AND p.post_author > 0
               AND p.post_author NOT IN (
                 SELECT customer_id FROM " . STELLAR_META_DB_PREFIX . "ltv_scores
                 WHERE scored_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               )
             LIMIT %d",
            $limit
        ), ARRAY_A );

        foreach ( $customers as $row ) {
            self::score_customer( (int) $row['customer_id'] );
        }
    }

    public static function score_customer( int $customer_id ): ?array {
        self::$settings = Stellar_Meta_Settings::instance();
        $key = self::$settings->openai_key();

        $history = self::get_order_history( $customer_id );
        if ( empty( $history ) ) return null;

        $prediction = $key ? self::predict_with_ai( $history, $key ) : self::predict_rfm( $history );
        if ( ! $prediction ) return null;

        self::store_score( $customer_id, $prediction, $history );

        // Sync high-LTV customers to Meta Value Optimization audience
        if ( in_array( $prediction['tier'], [ 'high', 'vip' ], true ) ) {
            self::sync_to_meta( $customer_id, $prediction );
        }

        return $prediction;
    }

    private static function predict_with_ai( array $history, string $api_key ): ?array {
        $prompt = sprintf(
            'Customer order history (JSON): %s

Predict:
1. Estimated 90-day LTV in %s
2. Estimated 12-month LTV in %s
3. LTV tier: "low" (<$100), "mid" ($100-500), "high" ($500-2000), "vip" (>$2000)

Respond ONLY with JSON: {"predicted_90d": X, "predicted_12m": Y, "tier": "Z"}',
            wp_json_encode( $history ),
            get_woocommerce_currency(),
            get_woocommerce_currency()
        );

        $response = wp_remote_post( self::OPENAI_URL, [
            'headers' => [ 'Authorization' => "Bearer {$api_key}", 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'model'           => self::MODEL,
                'temperature'     => 0.1,
                'max_tokens'      => 80,
                'messages'        => [
                    [ 'role' => 'system', 'content' => 'You are a customer LTV prediction model. Respond only with valid JSON.' ],
                    [ 'role' => 'user',   'content' => $prompt ],
                ],
                'response_format' => [ 'type' => 'json_object' ],
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) return null;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = $body['choices'][0]['message']['content'] ?? '';
        $data = json_decode( $text, true );
        if ( ! $data || ! isset( $data['predicted_90d'] ) ) return null;

        return [
            'predicted_90d' => (float) $data['predicted_90d'],
            'predicted_12m' => (float) $data['predicted_12m'],
            'tier'          => sanitize_text_field( $data['tier'] ?? 'low' ),
        ];
    }

    private static function predict_rfm( array $history ): array {
        // Simple RFM-based fallback when no OpenAI key
        $total   = array_sum( array_column( $history, 'total' ) );
        $count   = count( $history );
        $avg     = $count > 0 ? $total / $count : 0;
        $freq_90 = min( $count, 4 );
        $p90     = round( $avg * $freq_90, 2 );
        $p12m    = round( $avg * min( $count * 2, 24 ), 2 );
        $tier    = $p12m > 2000 ? 'vip' : ( $p12m > 500 ? 'high' : ( $p12m > 100 ? 'mid' : 'low' ) );
        return [ 'predicted_90d' => $p90, 'predicted_12m' => $p12m, 'tier' => $tier ];
    }

    private static function get_order_history( int $customer_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID AS order_id,
                    pm.meta_value AS total,
                    p.post_date   AS order_date
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_order_total'
             WHERE p.post_type='shop_order'
               AND p.post_status IN ('wc-completed','wc-processing')
               AND p.post_author=%d
             ORDER BY p.post_date ASC
             LIMIT 24",
            $customer_id
        ), ARRAY_A );

        return array_map( fn( $r ) => [
            'order_id'   => (int) $r['order_id'],
            'total'      => (float) $r['total'],
            'order_date' => $r['order_date'],
        ], $rows ?? [] );
    }

    private static function store_score( int $customer_id, array $prediction, array $history ): void {
        global $wpdb;
        $total_spend = array_sum( array_column( $history, 'total' ) );
        $wpdb->replace(
            STELLAR_META_DB_PREFIX . 'ltv_scores',
            [
                'customer_id'   => $customer_id,
                'predicted_90d' => $prediction['predicted_90d'],
                'predicted_12m' => $prediction['predicted_12m'],
                'ltv_tier'      => $prediction['tier'],
                'order_count'   => count( $history ),
                'total_spend'   => $total_spend,
                'scored_at'     => current_time('mysql'),
            ],
            [ '%d','%f','%f','%s','%d','%f','%s' ]
        );
    }

    private static function sync_to_meta( int $customer_id, array $prediction ): void {
        $user = get_user_by( 'id', $customer_id );
        if ( ! $user ) return;
        // Add to segment tagged 'high_ltv' — Audience Sync will push to Meta
        global $wpdb;
        $segment = $wpdb->get_row(
            "SELECT id FROM " . STELLAR_META_DB_PREFIX . "segments WHERE name='High LTV — Stellar Meta'",
            ARRAY_A
        );
        if ( ! $segment ) {
            $wpdb->insert( STELLAR_META_DB_PREFIX . 'segments', [
                'name'      => 'High LTV — Stellar Meta',
                'rules'     => wp_json_encode( [ 'ltv_tier' => [ 'high', 'vip' ] ] ),
                'auto_sync' => 1,
            ], [ '%s','%s','%d' ] );
            $segment = [ 'id' => $wpdb->insert_id ];
        }
        $wpdb->replace( STELLAR_META_DB_PREFIX . 'segment_members',
            [ 'segment_id' => $segment['id'], 'customer_id' => $customer_id ],
            [ '%d','%d' ]
        );
    }

    // Public getter for other classes
    public static function get_score( int $customer_id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . STELLAR_META_DB_PREFIX . "ltv_scores WHERE customer_id=%d",
            $customer_id
        ), ARRAY_A ) ?: null;
    }
}
