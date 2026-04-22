<?php
defined( 'ABSPATH' ) || exit;

/**
 * Anomaly Detection Engine.
 * Baselines normal patterns using 14-day rolling average.
 * Alerts via email + Slack when ROAS drops, conversions spike, or pixel fails.
 */
class Stellar_Meta_Anomaly_Detector {

    private static ?self $instance = null;
    private Stellar_Meta_Settings $settings;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        $this->settings = Stellar_Meta_Settings::instance();
    }

    public static function run(): void {
        $detector = self::instance();
        if ( ! $detector->settings->is_anomaly_enabled() ) return;
        $detector->check_pixel_health();
        $detector->check_event_volume();
        $detector->check_roas();
    }

    // ── Checks ────────────────────────────────────────────────────────────────

    public function check_pixel_health(): void {
        global $wpdb;
        // If zero CAPI events delivered in last 60 minutes — pixel may be broken
        $recent = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . STELLAR_META_DB_PREFIX . "event_queue
             WHERE status='delivered' AND fired_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)"
        );
        if ( $recent === 0 ) {
            // Check if there were any events queued at all
            $queued = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . STELLAR_META_DB_PREFIX . "event_queue
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)"
            );
            if ( $queued > 0 ) {
                $this->log_anomaly( 'pixel_delivery', 0, 0, 100, 'critical', 'Zero events delivered in 60 min — CAPI may be failing' );
            }
        }
    }

    public function check_event_volume(): void {
        global $wpdb;
        // Compare today's purchase count to 14-day baseline
        $today_purchases = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . STELLAR_META_DB_PREFIX . "event_queue
             WHERE event_name='Purchase' AND created_at >= CURDATE()"
        );
        $avg14 = (float) $wpdb->get_var(
            "SELECT AVG(cnt) FROM (
               SELECT COUNT(*) AS cnt FROM " . STELLAR_META_DB_PREFIX . "event_queue
               WHERE event_name='Purchase'
                 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                 AND created_at < CURDATE()
               GROUP BY DATE(created_at)
             ) AS daily"
        );

        if ( $avg14 < 1 ) return;
        $deviation = ( $today_purchases - $avg14 ) / $avg14 * 100;

        // Alert on significant drops (potential tracking issue)
        if ( $deviation < -50 ) {
            $this->log_anomaly( 'purchase_volume', $avg14, $today_purchases, abs($deviation), 'warning', 'Purchase volume significantly below 14-day average' );
        }
        // Alert on large spikes (potential fraud or bot traffic)
        if ( $deviation > 200 ) {
            $this->log_anomaly( 'purchase_volume_spike', $avg14, $today_purchases, $deviation, 'info', 'Purchase volume 3x above baseline — verify traffic quality' );
        }
    }

    public function check_roas(): void {
        global $wpdb;
        $threshold = $this->settings->anomaly_roas_drop_pct();
        $today = Stellar_Meta_ROAS_Tracker::get_roas( 1 );
        $avg14 = Stellar_Meta_ROAS_Tracker::get_roas( 14 );

        if ( $avg14 < 0.1 ) return;
        $deviation = ( $today - $avg14 ) / $avg14 * 100;

        if ( $deviation < -$threshold ) {
            $this->log_anomaly( 'roas_drop', $avg14, $today, abs($deviation), 'critical', "ROAS dropped {$threshold}%+ below 14-day average" );
        }
    }

    // ── Logging + Alerting ────────────────────────────────────────────────────

    private function log_anomaly( string $metric, float $baseline, float $observed, float $deviation, string $severity, string $notes ): void {
        global $wpdb;

        // Avoid re-alerting for same metric within 4 hours
        $recent = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . STELLAR_META_DB_PREFIX . "anomaly_log
             WHERE metric=%s AND created_at >= DATE_SUB(NOW(), INTERVAL 4 HOUR)
             LIMIT 1",
            $metric
        ) );
        if ( $recent ) return;

        $wpdb->insert( STELLAR_META_DB_PREFIX . 'anomaly_log', [
            'metric'         => $metric,
            'baseline_value' => $baseline,
            'observed_value' => $observed,
            'deviation_pct'  => $deviation,
            'severity'       => $severity,
            'alert_sent'     => 0,
            'notes'          => $notes,
            'created_at'     => current_time('mysql'),
        ], [ '%s','%f','%f','%f','%s','%d','%s','%s' ] );

        $anomaly_id = $wpdb->insert_id;
        $this->send_alert( $metric, $baseline, $observed, $deviation, $severity, $notes );

        $wpdb->update( STELLAR_META_DB_PREFIX . 'anomaly_log',
            [ 'alert_sent' => 1, 'alert_sent_at' => current_time('mysql') ],
            [ 'id' => $anomaly_id ], [ '%d','%s' ], [ '%d' ]
        );

        Stellar_Meta_Logger::warning( "Anomaly: {$metric}", compact( 'baseline','observed','deviation','severity' ) );
    }

    private function send_alert( string $metric, float $baseline, float $observed, float $deviation, string $severity, string $notes ): void {
        $site     = get_bloginfo('name');
        $emoji    = $severity === 'critical' ? '🚨' : '⚠️';
        $subject  = "{$emoji} Stellar Meta Alert — {$metric} on {$site}";
        $message  = "{$notes}\n\nMetric: {$metric}\nBaseline (14d avg): {$baseline}\nObserved: {$observed}\nDeviation: " . round($deviation,1) . "%\nSeverity: {$severity}\n\nTime: " . current_time('mysql');

        // Email alert
        $email = $this->settings->anomaly_alert_email();
        if ( $email ) {
            wp_mail( $email, $subject, $message );
        }

        // Slack webhook
        $webhook = $this->settings->anomaly_slack_webhook();
        if ( $webhook ) {
            wp_remote_post( $webhook, [
                'body'    => wp_json_encode( [ 'text' => "{$subject}\n```{$message}```" ] ),
                'headers' => [ 'Content-Type' => 'application/json' ],
                'timeout' => 5,
                'blocking'=> false,
            ] );
        }
    }

    public static function get_recent( int $limit = 20 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . STELLAR_META_DB_PREFIX . "anomaly_log
             ORDER BY created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A ) ?: [];
    }
}
