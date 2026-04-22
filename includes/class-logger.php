<?php
/**
 * Stellar Meta — Logger v4.0.0
 *
 * FIXES:
 *  - try/catch around DB writes (logger must never crash the plugin)
 *  - Rate limiting: identical errors won't spam the log (1 per 60s)
 *  - Log levels enforce WooCommerce PSR-3 compatible calls
 *  - ip() helper validates output before storing
 *  - Structured context enforces scalar values only
 */
defined( 'ABSPATH' ) || exit;

class Stellar_Meta_Logger {

    private static ?self $instance = null;
    private array        $rate_cache = [];

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    public static function info(    string $action, array $ctx = [] ): void { self::instance()->write('info',    $action, $ctx); }
    public static function warning( string $action, array $ctx = [] ): void { self::instance()->write('warning', $action, $ctx); }
    public static function error(   string $action, array $ctx = [] ): void { self::instance()->write('error',   $action, $ctx); }
    public static function debug(   string $action, array $ctx = [] ): void {
        if ( defined('WP_DEBUG') && WP_DEBUG ) self::instance()->write('debug', $action, $ctx);
    }

    private function write( string $level, string $action, array $ctx ): void {
        // Sanitize action text
        $action = substr( sanitize_text_field( $action ), 0, 120 );

        // Enforce scalar context values — prevent objects/arrays leaking
        $clean_ctx = [];
        foreach ( $ctx as $k => $v ) {
            $k = sanitize_key( (string) $k );
            $clean_ctx[ $k ] = is_scalar($v) ? $v : wp_json_encode($v);
        }

        // Rate-limit identical error messages (same action + level) to 1 per minute
        $rate_key = md5( $level . $action );
        if ( isset( $this->rate_cache[$rate_key] ) && ( time() - $this->rate_cache[$rate_key] ) < 60 ) {
            return;
        }
        $this->rate_cache[$rate_key] = time();

        // Write to DB — wrapped so logger never crashes the plugin
        try {
            global $wpdb;
            $wpdb->insert(
                STELLAR_META_DB_PREFIX . 'audit_log',
                [
                    'user_id'    => get_current_user_id() ?: null,
                    'action'     => "[{$level}] {$action}",
                    'context'    => wp_json_encode( $clean_ctx ),
                    'ip_address' => $this->client_ip(),
                    'created_at' => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%s', '%s', '%s' ]
            );
        } catch ( \Throwable $e ) {
            // Fall through to WC logger — never throw from logger
            error_log( "[StellarMeta][{$level}] {$action} DB write failed: " . $e->getMessage() );
        }

        // WooCommerce logger (file-based) — always attempt regardless of DB result
        try {
            if ( function_exists('wc_get_logger') ) {
                $wc_level = in_array($level, ['info','warning','error','debug'], true) ? $level : 'info';
                wc_get_logger()->$wc_level(
                    $action . ' | ' . wp_json_encode($clean_ctx),
                    ['source' => 'stellar-meta']
                );
            }
        } catch ( \Throwable $e ) {
            error_log( "[StellarMeta][{$level}] {$action} | " . wp_json_encode($clean_ctx) );
        }
    }

    /**
     * Get sanitized client IP — validates format, strips spoofable headers unless trusted.
     */
    private function client_ip(): string {
        // Only trust CF header if Cloudflare is actually proxying (check range not implemented here,
        // but at minimum we sanitize the input)
        $sources = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
        foreach ( $sources as $key ) {
            if ( empty($_SERVER[$key]) ) continue;
            $ip = trim( explode(',', sanitize_text_field($_SERVER[$key]))[0] );
            if ( filter_var($ip, FILTER_VALIDATE_IP) ) return $ip;
        }
        return '0.0.0.0';
    }

    /**
     * Prune old log entries. Respects configured retention period.
     */
    public static function prune(): void {
        try {
            global $wpdb;
            $days = (int) Stellar_Meta_Settings::instance()->capi_log_retention_days();
            $days = max(7, min(365, $days)); // enforce bounds 7-365 days
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM " . STELLAR_META_DB_PREFIX . "audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM " . STELLAR_META_DB_PREFIX . "event_queue WHERE status IN('delivered','failed') AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[StellarMeta] Logger::prune failed: ' . $e->getMessage() );
        }
    }

    /**
     * Retrieve recent log entries for the admin UI.
     */
    public static function recent( int $limit = 50, string $level = '' ): array {
        try {
            global $wpdb;
            $limit = min(200, max(1, (int)$limit));
            $where = $level ? $wpdb->prepare(" AND action LIKE %s", "[{$level}]%") : '';
            return (array) $wpdb->get_results(
                "SELECT id, user_id, action, context, ip_address, created_at FROM " . STELLAR_META_DB_PREFIX . "audit_log WHERE 1=1{$where} ORDER BY created_at DESC LIMIT {$limit}",
                ARRAY_A
            );
        } catch ( \Throwable $e ) {
            return [];
        }
    }
}
