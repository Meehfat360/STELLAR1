<?php
defined( 'ABSPATH' ) || exit;

/**
 * Revenue Heatmap — day-of-week × hour-of-day revenue grid.
 * Identifies best ad scheduling windows instantly.
 */
class Stellar_Meta_Revenue_Heatmap {

    public static function rebuild(): void {
        if ( ! Stellar_Meta_Settings::instance()->is_heatmap_enabled() ) return;
        global $wpdb;
        $today = gmdate( 'Y-m-d' );

        // Aggregate last 90 days of orders by day-of-week and hour
        $rows = $wpdb->get_results(
            "SELECT DAYOFWEEK(p.post_date)-2 AS dow,
                    HOUR(p.post_date)         AS h,
                    COUNT(*)                  AS orders,
                    SUM(pm.meta_value)        AS revenue
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_order_total'
             WHERE p.post_type='shop_order'
               AND p.post_status IN ('wc-completed','wc-processing')
               AND p.post_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             GROUP BY DAYOFWEEK(p.post_date), HOUR(p.post_date)",
            ARRAY_A
        ) ?: [];

        foreach ( $rows as $r ) {
            $orders  = (int) $r['orders'];
            $revenue = (float) $r['revenue'];
            $aov     = $orders > 0 ? round( $revenue / $orders, 2 ) : 0;
            $dow     = (int) $r['dow'];
            if ( $dow < 0 ) $dow += 7;

            $wpdb->replace( STELLAR_META_DB_PREFIX . 'revenue_heatmap', [
                'dow'             => $dow,
                'hour_of_day'     => (int) $r['h'],
                'orders'          => $orders,
                'revenue'         => $revenue,
                'avg_order_value' => $aov,
                'snapshot_date'   => $today,
            ], [ '%d','%d','%d','%f','%f','%s' ] );
        }
    }

    public static function get_grid(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT dow, hour_of_day, SUM(orders) AS orders, SUM(revenue) AS revenue,
                    ROUND(SUM(revenue)/NULLIF(SUM(orders),0),2) AS aov
             FROM " . STELLAR_META_DB_PREFIX . "revenue_heatmap
             GROUP BY dow, hour_of_day
             ORDER BY dow, hour_of_day",
            ARRAY_A
        ) ?: [];

        $grid = array_fill( 0, 7, array_fill( 0, 24, [ 'orders'=>0,'revenue'=>0.0,'aov'=>0.0 ] ) );
        foreach ( $rows as $r ) {
            $d = (int) $r['dow']; $h = (int) $r['hour_of_day'];
            if ( $d >= 0 && $d < 7 && $h >= 0 && $h < 24 ) {
                $grid[$d][$h] = [ 'orders'=>(int)$r['orders'], 'revenue'=>(float)$r['revenue'], 'aov'=>(float)$r['aov'] ];
            }
        }
        return $grid;
    }
}
