<?php
defined( 'ABSPATH' ) || exit;

/**
 * ROAS tracker — calculates revenue / ad spend ratio.
 */
class Stellar_Meta_ROAS_Tracker {

    public static function get_roas( int $days = 7 ): float {
        global $wpdb;
        $since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        $revenue  = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(pm.meta_value)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_order_total'
             WHERE p.post_type='shop_order'
               AND p.post_status IN ('wc-completed','wc-processing')
               AND p.post_date >= %s",
            $since
        ) );

        $ad_spend = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(ad_spend) FROM " . STELLAR_META_DB_PREFIX . "funnel_snapshots
             WHERE snapshot_date >= %s AND platform='meta'",
            $since
        ) );

        return $ad_spend > 0 ? round( $revenue / $ad_spend, 2 ) : 0.0;
    }

    public static function get_revenue( int $days = 7 ): float {
        global $wpdb;
        $since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(pm.meta_value),0)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_order_total'
             WHERE p.post_type='shop_order'
               AND p.post_status IN ('wc-completed','wc-processing')
               AND p.post_date >= %s",
            $since
        ) );
    }
}
