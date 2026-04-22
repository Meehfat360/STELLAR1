<?php
defined( 'ABSPATH' ) || exit;

/**
 * Churn Prediction Engine.
 * Scores every customer using RFM model daily.
 * High-risk customers auto-added to Meta win-back audience.
 */
class Stellar_Meta_Churn_Predictor {

    public static function run_batch( int $limit = 100 ): void {
        $settings = Stellar_Meta_Settings::instance();
        if ( ! $settings->is_churn_enabled() ) return;

        global $wpdb;
        $customers = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT p.post_author AS customer_id
             FROM {$wpdb->posts} p
             WHERE p.post_type='shop_order'
               AND p.post_status IN ('wc-completed','wc-processing')
               AND p.post_author > 0
             ORDER BY p.post_date DESC
             LIMIT %d",
            $limit
        ), ARRAY_A );

        foreach ( $customers as $row ) {
            self::score_customer( (int) $row['customer_id'] );
        }
    }

    public static function score_customer( int $customer_id ): ?array {
        global $wpdb;
        $settings = Stellar_Meta_Settings::instance();

        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*)                                   AS order_count,
                SUM(pm.meta_value)                         AS total_spend,
                MAX(p.post_date)                           AS last_order,
                DATEDIFF(NOW(), MAX(p.post_date))          AS days_since
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_order_total'
             WHERE p.post_type='shop_order'
               AND p.post_status IN ('wc-completed','wc-processing')
               AND p.post_author=%d",
            $customer_id
        ), ARRAY_A );

        if ( ! $stats || ! $stats['order_count'] ) return null;

        $recency   = (int) $stats['days_since'];
        $frequency = (int) $stats['order_count'];
        $monetary  = (float) $stats['total_spend'];
        $high_days = $settings->churn_high_risk_days();

        // RFM-based risk scoring
        $risk = 'low';
        if ( $recency > $high_days * 2 )      $risk = 'critical';
        elseif ( $recency > $high_days )       $risk = 'high';
        elseif ( $recency > $high_days / 2 )   $risk = 'medium';

        // AI score: 0-1 float (simple calculation without API call for performance)
        $ai_score = round( min( 1.0, $recency / ( $high_days * 3 ) ), 3 );

        $data = [
            'customer_id'   => $customer_id,
            'churn_risk'    => $risk,
            'rfm_recency'   => $recency,
            'rfm_frequency' => $frequency,
            'rfm_monetary'  => $monetary,
            'ai_risk_score' => $ai_score,
            'last_order_date' => $stats['last_order'] ? gmdate( 'Y-m-d', strtotime( $stats['last_order'] ) ) : null,
            'scored_at'     => current_time('mysql'),
        ];

        $wpdb->replace( STELLAR_META_DB_PREFIX . 'churn_scores', $data,
            [ '%d','%s','%d','%d','%f','%f','%s','%s' ] );

        // Auto-add high/critical risk customers to win-back audience
        if ( in_array( $risk, [ 'high', 'critical' ], true ) ) {
            self::add_to_winback_audience( $customer_id );
        }

        return $data;
    }

    private static function add_to_winback_audience( int $customer_id ): void {
        global $wpdb;
        $segment = $wpdb->get_row(
            "SELECT id FROM " . STELLAR_META_DB_PREFIX . "segments WHERE name='Win-back — Stellar Meta'",
            ARRAY_A
        );
        if ( ! $segment ) {
            $wpdb->insert( STELLAR_META_DB_PREFIX . 'segments', [
                'name'      => 'Win-back — Stellar Meta',
                'rules'     => wp_json_encode( [ 'churn_risk' => [ 'high', 'critical' ] ] ),
                'auto_sync' => 1,
            ], [ '%s','%s','%d' ] );
            $segment = [ 'id' => $wpdb->insert_id ];
        }
        $wpdb->replace( STELLAR_META_DB_PREFIX . 'segment_members',
            [ 'segment_id' => $segment['id'], 'customer_id' => $customer_id ],
            [ '%d','%d' ]
        );
        $wpdb->update( STELLAR_META_DB_PREFIX . 'churn_scores',
            [ 'in_winback_audience' => 1 ], [ 'customer_id' => $customer_id ],
            [ '%d' ], [ '%d' ]
        );
    }

    public static function get_summary(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT churn_risk, COUNT(*) AS cnt FROM " . STELLAR_META_DB_PREFIX . "churn_scores GROUP BY churn_risk",
            ARRAY_A
        ) ?: [];
        $summary = [ 'low'=>0, 'medium'=>0, 'high'=>0, 'critical'=>0 ];
        foreach ( $rows as $r ) $summary[ $r['churn_risk'] ] = (int) $r['cnt'];
        return $summary;
    }

    public static function get_at_risk( int $limit = 20 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT cs.*, u.display_name, u.user_email
             FROM " . STELLAR_META_DB_PREFIX . "churn_scores cs
             LEFT JOIN {$wpdb->users} u ON u.ID = cs.customer_id
             WHERE cs.churn_risk IN ('high','critical')
             ORDER BY cs.ai_risk_score DESC
             LIMIT %d",
            $limit
        ), ARRAY_A ) ?: [];
    }
}
