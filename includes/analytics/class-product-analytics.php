<?php
defined( 'ABSPATH' ) || exit;

/**
 * Product-Level Attribution & Analytics Engine.
 *
 * Tracks per-product: ViewContent count, AddToCart count, Purchase count,
 * Conversion Rate, AOV, Repeat Purchase Rate, Cross-sell pairs, and
 * per-channel ROAS attribution.
 */
class Stellar_Meta_Product_Analytics {

    // ── Public data getters ────────────────────────────────────────────────────

    /**
     * Full performance scorecard for all products.
     */
    public static function get_scorecard( int $days = 30 ): array {
        global $wpdb;
        $since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        // Purchase counts + revenue per product from WooCommerce order items
        $purchase_data = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                oi.order_item_name                   AS product_name,
                oim_id.meta_value                    AS product_id,
                COUNT(DISTINCT o.ID)                 AS purchase_count,
                SUM(oim_qty.meta_value)              AS units_sold,
                SUM(oim_total.meta_value)            AS revenue
             FROM {$wpdb->posts} o
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
                ON oi.order_id = o.ID AND oi.order_item_type = 'line_item'
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_id
                ON oim_id.order_item_id = oi.order_item_id AND oim_id.meta_key = '_product_id'
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
                ON oim_qty.order_item_id = oi.order_item_id AND oim_qty.meta_key = '_qty'
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total
                ON oim_total.order_item_id = oi.order_item_id AND oim_total.meta_key = '_line_total'
             WHERE o.post_type   = 'shop_order'
               AND o.post_status IN ('wc-completed','wc-processing')
               AND o.post_date  >= %s
             GROUP BY oim_id.meta_value
             ORDER BY revenue DESC
             LIMIT 100",
            $since
        ), ARRAY_A ) ?: [];

        // ViewContent + AddToCart from event queue
        $event_data = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                event_name,
                JSON_UNQUOTE(JSON_EXTRACT(payload,'$.custom_data.content_ids[0]')) AS product_id,
                COUNT(*) AS cnt
             FROM " . STELLAR_META_DB_PREFIX . "event_queue
             WHERE event_name IN ('ViewContent','AddToCart')
               AND created_at >= %s
               AND JSON_EXTRACT(payload,'$.custom_data.content_ids[0]') IS NOT NULL
             GROUP BY event_name, product_id",
            $since
        ), ARRAY_A ) ?: [];

        // Index event data
        $events = [];
        foreach ( $event_data as $row ) {
            $pid = (string) trim( $row['product_id'], '"' );
            $events[ $pid ][ $row['event_name'] ] = (int) $row['cnt'];
        }

        // Merge + compute rates
        $scorecard = [];
        foreach ( $purchase_data as $row ) {
            $pid      = (string) $row['product_id'];
            $views    = (int) ( $events[$pid]['ViewContent'] ?? 0 );
            $carts    = (int) ( $events[$pid]['AddToCart']   ?? 0 );
            $buys     = (int) $row['purchase_count'];
            $revenue  = (float) $row['revenue'];
            $units    = (int) $row['units_sold'];
            $aov      = $buys > 0 ? round( $revenue / $buys, 2 ) : 0;
            $view_cvr = $views > 0 ? round( $buys / $views * 100, 2 ) : 0;
            $cart_cvr = $carts > 0 ? round( $buys / $carts * 100, 2 ) : 0;

            $scorecard[] = [
                'product_id'    => $pid,
                'product_name'  => $row['product_name'],
                'views'         => $views,
                'add_to_cart'   => $carts,
                'purchases'     => $buys,
                'units_sold'    => $units,
                'revenue'       => $revenue,
                'aov'           => $aov,
                'view_cvr'      => $view_cvr,
                'cart_cvr'      => $cart_cvr,
            ];
        }

        return $scorecard;
    }

    /**
     * Cross-sell pairs: products frequently bought together.
     */
    public static function get_cross_sell_pairs( int $limit = 20 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                a.meta_value  AS product_a,
                b.meta_value  AS product_b,
                COUNT(*)      AS co_purchases
             FROM {$wpdb->prefix}woocommerce_order_itemmeta a
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta b
                ON  b.order_item_id != a.order_item_id
                AND b.meta_key = '_product_id'
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oia ON oia.order_item_id = a.order_item_id
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oib ON oib.order_item_id = b.order_item_id
                AND oib.order_id = oia.order_id
             INNER JOIN {$wpdb->posts} o ON o.ID = oia.order_id
                AND o.post_status IN ('wc-completed','wc-processing')
                AND o.post_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             WHERE a.meta_key = '_product_id'
               AND a.meta_value < b.meta_value
             GROUP BY a.meta_value, b.meta_value
             ORDER BY co_purchases DESC
             LIMIT %d",
            $limit
        ), ARRAY_A ) ?: [];
    }

    /**
     * Top trending products: this week vs prior week.
     */
    public static function get_trending( int $top = 10 ): array {
        global $wpdb;

        $query = "SELECT
            oim.meta_value                          AS product_id,
            oi.order_item_name                      AS product_name,
            SUM(CASE WHEN o.post_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)  THEN 1 ELSE 0 END) AS this_week,
            SUM(CASE WHEN o.post_date  < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                      AND o.post_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) AS prev_week
        FROM {$wpdb->posts} o
        INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = o.ID AND oi.order_item_type='line_item'
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id AND oim.meta_key='_product_id'
        WHERE o.post_type='shop_order' AND o.post_status IN('wc-completed','wc-processing')
          AND o.post_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY oim.meta_value
        HAVING this_week > 0
        ORDER BY this_week DESC
        LIMIT {$top}";

        $rows = $wpdb->get_results( $query, ARRAY_A ) ?: [];

        return array_map( function($r) {
            $prev  = max(1, (int)$r['prev_week']);
            $curr  = (int)$r['this_week'];
            $change = round( ($curr - $prev) / $prev * 100 );
            return array_merge($r, ['change_pct' => $change, 'trending' => $change > 0]);
        }, $rows );
    }

    /**
     * Repeat purchase rate per product.
     */
    public static function get_repeat_rates(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT
                oim.meta_value                                          AS product_id,
                oi.order_item_name                                      AS product_name,
                COUNT(DISTINCT o.post_author)                           AS total_buyers,
                SUM(CASE WHEN buyer_counts.order_count > 1 THEN 1 ELSE 0 END) AS repeat_buyers
             FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
             INNER JOIN {$wpdb->posts} o ON o.ID = oi.order_id
                AND o.post_type = 'shop_order'
                AND o.post_status IN ('wc-completed','wc-processing')
             LEFT JOIN (
                SELECT post_author, COUNT(*) AS order_count
                FROM {$wpdb->posts}
                WHERE post_type='shop_order' AND post_status IN('wc-completed','wc-processing')
                GROUP BY post_author
             ) AS buyer_counts ON buyer_counts.post_author = o.post_author
             WHERE oim.meta_key = '_product_id' AND o.post_author > 0
             GROUP BY oim.meta_value
             HAVING total_buyers > 0
             ORDER BY repeat_buyers DESC
             LIMIT 50",
            ARRAY_A
        ) ?: [];
    }
}
