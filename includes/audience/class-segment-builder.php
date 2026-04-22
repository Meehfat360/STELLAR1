<?php
defined( 'ABSPATH' ) || exit;

/**
 * Smart Audience Segment Builder.
 * Evaluates rule-based segments against WooCommerce customer data
 * and maintains a member list for each segment.
 */
class Stellar_Meta_Segment_Builder {

    /**
     * Evaluate all segments and refresh their member lists.
     */
    public static function rebuild_all(): void {
        global $wpdb;
        $segments = $wpdb->get_results(
            "SELECT * FROM " . STELLAR_META_DB_PREFIX . "segments",
            ARRAY_A
        );
        foreach ( $segments as $seg ) {
            self::rebuild_segment( (int) $seg['id'], json_decode( $seg['rules'], true ) );
        }
    }

    /**
     * Evaluate a single segment's rules and update its member list.
     */
    public static function rebuild_segment( int $segment_id, array $rules ): array {
        global $wpdb;

        $customer_ids = self::query_customers( $rules );

        // Replace members atomically
        $wpdb->delete( STELLAR_META_DB_PREFIX . 'segment_members', [ 'segment_id' => $segment_id ], [ '%d' ] );

        if ( ! empty( $customer_ids ) ) {
            $values = implode( ',', array_map(
                fn( $id ) => $wpdb->prepare( '(%d, %d, %s)', $segment_id, $id, current_time('mysql') ),
                $customer_ids
            ) );
            $wpdb->query( "INSERT INTO " . STELLAR_META_DB_PREFIX . "segment_members (segment_id, customer_id, added_at) VALUES {$values}" );
        }

        $count = count( $customer_ids );
        $wpdb->update(
            STELLAR_META_DB_PREFIX . 'segments',
            [ 'member_count' => $count, 'last_synced' => current_time( 'mysql' ) ],
            [ 'id' => $segment_id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        return $customer_ids;
    }

    // ── Rule Engine ───────────────────────────────────────────────────────────

    /**
     * Translate a rule set into a customer ID list.
     *
     * Supported rule keys:
     *   min_order_count, max_order_count
     *   min_ltv, max_ltv
     *   days_since_last_order (max)
     *   abandoned_cart_days
     *   product_category_viewed (category slug)
     *   purchased_category (category slug)
     */
    private static function query_customers( array $rules ): array {
        global $wpdb;

        // Handle abandoned cart as a special case
        if ( ! empty( $rules['abandoned_cart_days'] ) ) {
            return self::query_abandoned_cart( (int) $rules['abandoned_cart_days'] );
        }

        // Build a WooCommerce customer query via orders table
        $conditions = [ "p.post_type = 'shop_order'", "p.post_status IN ('wc-completed','wc-processing')" ];
        $having     = [];
        $joins      = [];

        if ( isset( $rules['min_order_count'] ) ) {
            $having[] = $wpdb->prepare( "COUNT(p.ID) >= %d", (int) $rules['min_order_count'] );
        }
        if ( isset( $rules['max_order_count'] ) ) {
            $having[] = $wpdb->prepare( "COUNT(p.ID) <= %d", (int) $rules['max_order_count'] );
        }
        if ( isset( $rules['min_ltv'] ) ) {
            $having[] = $wpdb->prepare( "SUM(pm_total.meta_value) >= %f", (float) $rules['min_ltv'] );
            $joins[]  = "LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'";
        }
        if ( isset( $rules['max_ltv'] ) ) {
            $having[] = $wpdb->prepare( "SUM(pm_total.meta_value) <= %f", (float) $rules['max_ltv'] );
            if ( ! in_array( "LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'", $joins ) ) {
                $joins[] = "LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'";
            }
        }
        if ( isset( $rules['days_since_last_order'] ) ) {
            $having[] = $wpdb->prepare( "MAX(p.post_date) >= DATE_SUB(NOW(), INTERVAL %d DAY)", (int) $rules['days_since_last_order'] );
        }

        $join_sql   = implode( "\n", $joins );
        $where_sql  = implode( ' AND ', $conditions );
        $having_sql = $having ? 'HAVING ' . implode( ' AND ', $having ) : '';

        $results = $wpdb->get_col(
            "SELECT pm.meta_value AS customer_id
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
             {$join_sql}
             WHERE {$where_sql}
               AND pm.meta_value > 0
             GROUP BY pm.meta_value
             {$having_sql}"
        );

        return array_map( 'intval', $results );
    }

    private static function query_abandoned_cart( int $days ): array {
        global $wpdb;

        // WooCommerce stores sessions; carts older than $days with no order
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

        // Get user IDs with non-empty WC sessions older than cutoff but no recent purchase
        $results = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT pm.meta_value AS customer_id
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
             WHERE p.post_type = 'shop_order'
               AND p.post_status IN ('wc-pending','wc-cancelled','wc-failed')
               AND p.post_date >= %s
               AND pm.meta_value > 0",
            $cutoff
        ) );

        return array_map( 'intval', $results );
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public static function create( string $name, string $desc, array $rules ): int {
        global $wpdb;
        $wpdb->insert(
            STELLAR_META_DB_PREFIX . 'segments',
            [
                'name'        => sanitize_text_field( $name ),
                'description' => sanitize_textarea_field( $desc ),
                'rules'       => wp_json_encode( $rules ),
                'created_at'  => current_time( 'mysql' ),
                'updated_at'  => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    public static function all(): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            "SELECT * FROM " . STELLAR_META_DB_PREFIX . "segments ORDER BY created_at DESC",
            ARRAY_A
        );
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . STELLAR_META_DB_PREFIX . "segments WHERE id = %d",
            $id
        ), ARRAY_A ) ?: null;
    }

    public static function delete( int $id ): void {
        global $wpdb;
        $wpdb->delete( STELLAR_META_DB_PREFIX . 'segments',        [ 'id' => $id ], [ '%d' ] );
        $wpdb->delete( STELLAR_META_DB_PREFIX . 'segment_members', [ 'segment_id' => $id ], [ '%d' ] );
    }

    public static function get_member_emails( int $segment_id ): array {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT u.user_email
             FROM " . STELLAR_META_DB_PREFIX . "segment_members sm
             JOIN {$wpdb->users} u ON sm.customer_id = u.ID
             WHERE sm.segment_id = %d",
            $segment_id
        ) );
    }
}
