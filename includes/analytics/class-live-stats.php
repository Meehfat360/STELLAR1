<?php
defined( 'ABSPATH' ) || exit;

/**
 * Live Stats Engine — powers the real-time dashboard.
 *
 * All methods are designed to be called from AJAX every N seconds.
 * Queries are lightweight (bounded by time window + LIMIT).
 */
class Stellar_Meta_Live_Stats {

    /**
     * Real-time event stream — last N events across all platforms.
     */
    public static function get_live_events( int $limit = 15 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                eq.id, eq.event_name, eq.platform, eq.status,
                eq.latency_ms, eq.error_message, eq.created_at,
                JSON_UNQUOTE(JSON_EXTRACT(eq.payload, '$.custom_data.content_name'))  AS product_name,
                JSON_UNQUOTE(JSON_EXTRACT(eq.payload, '$.custom_data.value'))         AS value,
                JSON_UNQUOTE(JSON_EXTRACT(eq.payload, '$.custom_data.currency'))      AS currency,
                JSON_UNQUOTE(JSON_EXTRACT(eq.payload, '$.user_data.em'))              AS email_hash
             FROM " . STELLAR_META_DB_PREFIX . "event_queue eq
             ORDER BY eq.created_at DESC
             LIMIT %d",
            $limit
        ), ARRAY_A ) ?: [];

        return array_map( function($row) {
            $row['value']        = $row['value'] ? (float) $row['value'] : null;
            $row['product_name'] = $row['product_name'] ? trim($row['product_name'], '"') : null;
            $row['time_ago']     = self::time_ago( $row['created_at'] );
            return $row;
        }, $rows );
    }

    /**
     * Live funnel counts — last 24 hours.
     */
    public static function get_live_funnel(): array {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT
                SUM(event_name='ViewContent')        AS view_content,
                SUM(event_name='AddToCart')          AS add_to_cart,
                SUM(event_name='InitiateCheckout')   AS initiate_checkout,
                SUM(event_name='Purchase')           AS purchase,
                SUM(CASE WHEN event_name='Purchase' THEN
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.custom_data.value')) AS DECIMAL(12,2))
                ELSE 0 END)                          AS revenue_24h
             FROM " . STELLAR_META_DB_PREFIX . "event_queue
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND status IN ('delivered','pending','processing')",
            ARRAY_A
        );

        $vc = (int)($row['view_content'] ?? 0);
        $ac = (int)($row['add_to_cart'] ?? 0);
        $ic = (int)($row['initiate_checkout'] ?? 0);
        $pu = (int)($row['purchase'] ?? 0);

        return [
            'view_content'      => $vc,
            'add_to_cart'       => $ac,
            'initiate_checkout' => $ic,
            'purchase'          => $pu,
            'revenue_24h'       => (float)($row['revenue_24h'] ?? 0),
            'cart_rate'         => $vc > 0 ? round($ac / $vc * 100, 1) : 0,
            'checkout_rate'     => $ac > 0 ? round($ic / $ac * 100, 1) : 0,
            'purchase_rate'     => $ic > 0 ? round($pu / $ic * 100, 1) : 0,
            'overall_cvr'       => $vc > 0 ? round($pu / $vc * 100, 2) : 0,
        ];
    }

    /**
     * Live metrics — events per minute, revenue per hour, CAPI match rate.
     */
    public static function get_live_metrics(): array {
        global $wpdb;

        $epm = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . STELLAR_META_DB_PREFIX . "event_queue
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        );

        $rev_hour = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(CAST(
                JSON_UNQUOTE(JSON_EXTRACT(payload,'$.custom_data.value')) AS DECIMAL(12,2)
             )),0)
             FROM " . STELLAR_META_DB_PREFIX . "event_queue
             WHERE event_name='Purchase'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );

        $stats  = Stellar_Meta_Event_Queue::stats();
        $total  = max(1, $stats['delivered'] + $stats['failed']);
        $match_rate = $total > 0 ? round( $stats['delivered'] / $total * 100, 1 ) : 0;

        $roas_live = Stellar_Meta_ROAS_Tracker::get_roas(1);

        $open_anomalies = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . STELLAR_META_DB_PREFIX . "anomaly_log
             WHERE resolved_at IS NULL
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        return [
            'events_per_minute' => $epm,
            'revenue_per_hour'  => round($rev_hour, 2),
            'capi_match_rate'   => $match_rate,
            'roas_today'        => $roas_live,
            'queue_depth'       => $stats['queue_depth'],
            'open_anomalies'    => $open_anomalies,
            'delivered_24h'     => $stats['delivered'],
            'failed_24h'        => $stats['failed'],
            'avg_latency_ms'    => $stats['avg_latency'],
        ];
    }

    /**
     * Live customer activity feed — recent orders with customer name + product.
     */
    public static function get_activity_feed( int $limit = 10 ): array {
        global $wpdb;

        // Recent orders
        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                p.ID                    AS order_id,
                p.post_date             AS order_date,
                pm_total.meta_value     AS total,
                pm_fname.meta_value     AS first_name,
                pm_lname.meta_value     AS last_name,
                pm_email.meta_value     AS email
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_total  ON pm_total.post_id  = p.ID AND pm_total.meta_key  = '_order_total'
             LEFT JOIN {$wpdb->postmeta} pm_fname  ON pm_fname.post_id  = p.ID AND pm_fname.meta_key  = '_billing_first_name'
             LEFT JOIN {$wpdb->postmeta} pm_lname  ON pm_lname.post_id  = p.ID AND pm_lname.meta_key  = '_billing_last_name'
             LEFT JOIN {$wpdb->postmeta} pm_email  ON pm_email.post_id  = p.ID AND pm_email.meta_key  = '_billing_email'
             WHERE p.post_type    = 'shop_order'
               AND p.post_status IN ('wc-completed','wc-processing')
               AND p.post_date   >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY p.post_date DESC
             LIMIT %d",
            $limit
        ), ARRAY_A ) ?: [];

        $feed = [];
        foreach ( $orders as $order ) {
            $first = $order['first_name'] ?: 'Customer';
            $last  = $order['last_name']  ? substr($order['last_name'],0,1).'.' : '';
            // Mask email for privacy
            $email_parts = explode('@', $order['email'] ?: '');
            $masked_email = strlen($email_parts[0]??'') > 2
                ? substr($email_parts[0],0,2) . '***@' . ($email_parts[1]??'')
                : '***';

            // Get first product name
            $product_name = $wpdb->get_var( $wpdb->prepare(
                "SELECT oi.order_item_name
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 WHERE oi.order_id = %d AND oi.order_item_type = 'line_item'
                 LIMIT 1",
                $order['order_id']
            ) ) ?: 'Order';

            $feed[] = [
                'order_id'     => (int)$order['order_id'],
                'name'         => trim("{$first} {$last}"),
                'email_masked' => $masked_email,
                'total'        => (float)$order['total'],
                'product_name' => $product_name,
                'time_ago'     => self::time_ago($order['order_date']),
                'type'         => 'purchase',
            ];
        }

        // Merge recent AddToCart events
        $carts = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                JSON_UNQUOTE(JSON_EXTRACT(payload,'$.custom_data.content_name')) AS product_name,
                JSON_UNQUOTE(JSON_EXTRACT(payload,'$.custom_data.value'))        AS value,
                created_at
             FROM " . STELLAR_META_DB_PREFIX . "event_queue
             WHERE event_name = 'AddToCart'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
             ORDER BY created_at DESC
             LIMIT 5",
            []
        ), ARRAY_A ) ?: [];

        foreach ( $carts as $cart ) {
            $feed[] = [
                'name'         => 'Visitor',
                'product_name' => trim($cart['product_name']??'Product', '"'),
                'total'        => (float)($cart['value']??0),
                'time_ago'     => self::time_ago($cart['created_at']),
                'type'         => 'add_to_cart',
            ];
        }

        // Sort by recency
        usort($feed, fn($a,$b) => strcmp($b['time_ago'],$a['time_ago']));
        return array_slice($feed, 0, $limit);
    }

    // ── Helper ─────────────────────────────────────────────────────────────────

    private static function time_ago( string $datetime ): string {
        $diff = max(0, time() - strtotime($datetime));
        if ($diff < 60)   return $diff . 's ago';
        if ($diff < 3600) return floor($diff/60) . 'm ago';
        if ($diff < 86400)return floor($diff/3600) . 'h ago';
        return floor($diff/86400) . 'd ago';
    }
}
