<?php defined( 'ABSPATH' ) || exit;

/**
 * Event deduplication guard.
 * Uses a DB table to track sent event_ids within the configured window.
 */
class Stellar_Meta_Deduplicator {

    public static function is_duplicate( string $event_id ): bool {
        global $wpdb;
        $hours = Stellar_Meta_Settings::instance()->dedup_window_hours();
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . STELLAR_META_DB_PREFIX . "pixel_log
             WHERE event_id = %s AND fired_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $event_id, $hours
        ) );
        return $count > 0;
    }

    public static function mark_sent( string $event_id, string $event_name = '' ): void {
        global $wpdb;
        $wpdb->replace(
            STELLAR_META_DB_PREFIX . 'pixel_log',
            [
                'event_id'   => $event_id,
                'event_name' => $event_name,
                'fired_at'   => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s' ]
        );
    }
}
