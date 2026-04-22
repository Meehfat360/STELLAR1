<?php
defined( 'ABSPATH' ) || exit;

/**
 * Enterprise event queue processor.
 * Runs on a 1-minute WP-Cron schedule. Picks up pending/retrying
 * events, dispatches them via CAPI, and updates their status.
 */
class Stellar_Meta_Event_Queue {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Process up to $batch pending events.
     * Called by WP-Cron every minute.
     */
    public static function process( int $batch = 50 ): void {
        global $wpdb;
        $table = STELLAR_META_DB_PREFIX . 'event_queue';

        // Fetch eligible events (pending or due for retry)
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status IN ('pending','retrying')
               AND scheduled_at <= %s
               AND attempts < max_attempts
             ORDER BY scheduled_at ASC
             LIMIT %d",
            current_time( 'mysql' ),
            $batch
        ), ARRAY_A );

        if ( empty( $rows ) ) return;

        $capi = Stellar_Meta_CAPI::instance();

        foreach ( $rows as $row ) {
            // Mark as processing to prevent concurrent runs picking the same row
            $wpdb->update(
                $table,
                [ 'status' => 'processing', 'attempts' => (int) $row['attempts'] + 1 ],
                [ 'id' => $row['id'] ],
                [ '%s', '%d' ],
                [ '%d' ]
            );

            $result = $capi->dispatch( $row );

            if ( $result['success'] ) {
                $wpdb->update(
                    $table,
                    [
                        'status'     => 'delivered',
                        'latency_ms' => $result['latency_ms'],
                        'fired_at'   => current_time( 'mysql' ),
                    ],
                    [ 'id' => $row['id'] ],
                    [ '%s', '%d', '%s' ],
                    [ '%d' ]
                );

                // Record for deduplication
                Stellar_Meta_Deduplicator::mark_sent( $row['event_id'] );

            } else {
                $new_attempts = (int) $row['attempts'] + 1;
                $max          = (int) $row['max_attempts'];
                $backoff_secs = self::backoff( $new_attempts );

                if ( $new_attempts >= $max ) {
                    $wpdb->update(
                        $table,
                        [
                            'status'        => 'failed',
                            'error_message' => $result['message'],
                            'fired_at'      => current_time( 'mysql' ),
                        ],
                        [ 'id' => $row['id'] ],
                        [ '%s', '%s', '%s' ],
                        [ '%d' ]
                    );
                } else {
                    $next = gmdate( 'Y-m-d H:i:s', time() + $backoff_secs );
                    $wpdb->update(
                        $table,
                        [
                            'status'        => 'retrying',
                            'error_message' => $result['message'],
                            'scheduled_at'  => $next,
                        ],
                        [ 'id' => $row['id'] ],
                        [ '%s', '%s', '%s' ],
                        [ '%d' ]
                    );
                }
            }
        }

        // Prune old log entries periodically
        if ( rand( 1, 20 ) === 1 ) {
            Stellar_Meta_Logger::prune();
        }
    }

    /**
     * Exponential backoff: attempt 1 = 30s, 2 = 90s, 3 = 270s …
     */
    private static function backoff( int $attempt ): int {
        $base = Stellar_Meta_Settings::instance()->capi_retry_backoff();
        return (int) ( $base * pow( 3, $attempt - 1 ) );
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public static function stats(): array {
        global $wpdb;
        $table = STELLAR_META_DB_PREFIX . 'event_queue';

        $row = $wpdb->get_row(
            "SELECT
                SUM(status = 'delivered')   AS delivered,
                SUM(status = 'retrying')    AS retrying,
                SUM(status = 'failed')      AS failed,
                SUM(status = 'pending')     AS pending,
                ROUND(AVG(latency_ms), 0)   AS avg_latency,
                SUM(status = 'pending' OR status = 'retrying') AS queue_depth
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            ARRAY_A
        );

        return array_map( fn( $v ) => (int) ( $v ?? 0 ), (array) $row );
    }

    public static function recent_events( int $limit = 20 ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event_name, status, attempts, latency_ms, error_message, created_at, fired_at
             FROM " . STELLAR_META_DB_PREFIX . "event_queue
             ORDER BY created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A );
    }
}
