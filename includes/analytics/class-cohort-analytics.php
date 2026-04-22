<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cohort Analytics Engine.
 * Builds retention curves and cohort ROAS — same depth as Mixpanel.
 */
class Stellar_Meta_Cohort_Analytics {

    public static function rebuild(): void {
        if ( ! Stellar_Meta_Settings::instance()->is_cohort_enabled() ) return;
        global $wpdb;

        // Get all months with orders from last 24 months
        $months = $wpdb->get_col(
            "SELECT DISTINCT DATE_FORMAT(post_date,'%Y-%m-01') AS cohort_month
             FROM {$wpdb->posts}
             WHERE post_type='shop_order'
               AND post_status IN ('wc-completed','wc-processing')
               AND post_date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
             ORDER BY cohort_month ASC"
        );

        foreach ( $months as $cohort_month ) {
            self::build_cohort( $cohort_month );
        }
    }

    private static function build_cohort( string $cohort_month ): void {
        global $wpdb;

        // Get acquisition customers (first order in cohort month)
        $customers = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_author FROM {$wpdb->posts}
             WHERE post_type='shop_order'
               AND post_status IN ('wc-completed','wc-processing')
               AND DATE_FORMAT(post_date,'%Y-%m-01')=%s
               AND post_author > 0
               AND post_author NOT IN (
                 SELECT DISTINCT post_author FROM {$wpdb->posts}
                 WHERE post_type='shop_order'
                   AND post_status IN ('wc-completed','wc-processing')
                   AND post_date < %s
                   AND post_author > 0
               )
             GROUP BY post_author",
            $cohort_month, $cohort_month
        ) );

        if ( empty( $customers ) ) return;

        $acquired = count( $customers );
        $ids_in   = implode( ',', array_map( 'intval', $customers ) );

        // For each period offset (0 = acquisition month, 1 = M+1, etc.)
        for ( $offset = 0; $offset <= 11; $offset++ ) {
            $period_start = gmdate( 'Y-m-01', strtotime( "+{$offset} month", strtotime( $cohort_month ) ) );
            $period_end   = gmdate( 'Y-m-t 23:59:59', strtotime( $period_start ) );

            $stats = $wpdb->get_row( $wpdb->prepare(
                "SELECT COUNT(DISTINCT post_author) AS customers,
                        SUM(pm.meta_value) AS revenue,
                        COUNT(*) AS orders
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_order_total'
                 WHERE p.post_type='shop_order'
                   AND p.post_status IN ('wc-completed','wc-processing')
                   AND p.post_author IN ({$ids_in})
                   AND p.post_date BETWEEN %s AND %s",
                $period_start, $period_end
            ), ARRAY_A );

            $active      = (int) ( $stats['customers'] ?? 0 );
            $revenue     = (float) ( $stats['revenue'] ?? 0 );
            $orders      = (int) ( $stats['orders'] ?? 0 );
            $retention   = $acquired > 0 ? round( $active / $acquired * 100, 2 ) : 0;

            global $wpdb;
            $wpdb->replace( STELLAR_META_DB_PREFIX . 'cohort_data', [
                'cohort_month'  => $cohort_month,
                'period_offset' => $offset,
                'customers'     => $active,
                'revenue'       => $revenue,
                'orders'        => $orders,
                'retention_pct' => $retention,
            ], [ '%s','%d','%d','%f','%d','%f' ] );
        }
    }

    public static function get_cohort_table( int $months = 12 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . STELLAR_META_DB_PREFIX . "cohort_data
             WHERE cohort_month >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL %d MONTH),'%Y-%m-01')
             ORDER BY cohort_month ASC, period_offset ASC",
            $months
        ), ARRAY_A ) ?: [];

        $table = [];
        foreach ( $rows as $row ) {
            $month   = $row['cohort_month'];
            $offset  = (int) $row['period_offset'];
            if ( ! isset( $table[ $month ] ) ) $table[ $month ] = [];
            $table[ $month ][ $offset ] = $row;
        }
        return $table;
    }
}
