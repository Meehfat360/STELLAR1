<?php
/**
 * Utility helpers.
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class SOS_Utils
{
    /**
     * Option key storing current schema/plugin version.
     */
    public const VERSION_OPTION = 'sos_plugin_version';

    /**
     * Capability used by the plugin.
     */
    public const CAPABILITY = 'manage_woocommerce';

    /**
     * Prefix for transients or locks.
     */
    public const LOCK_PREFIX = 'sos_lock_';

    /**
     * Disallow instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Check environment compatibility.
     */
    public static function is_environment_compatible(): bool
    {
        global $wp_version;

        if (version_compare(PHP_VERSION, SOS_MINIMUM_PHP_VERSION, '<')) {
            return false;
        }

        if (! isset($wp_version) || version_compare((string) $wp_version, SOS_MINIMUM_WP_VERSION, '<')) {
            return false;
        }

        return true;
    }

    /**
     * Verify plugin management capability.
     */
    public static function current_user_can_manage_plugin(): bool
    {
        if (! is_user_logged_in()) {
            return false;
        }

        if (current_user_can(self::CAPABILITY) || current_user_can('manage_options')) {
            return true;
        }

        return false;
    }

    /**
     * Current MySQL datetime in UTC.
     */
    public static function mysql_now_utc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Sanitize enum-like values with fallback.
     *
     * @param array<int, string> $allowed_values Allowed values.
     */
    public static function sanitize_enum(string $value, array $allowed_values, string $fallback): string
    {
        $value = sanitize_key($value);

        return in_array($value, $allowed_values, true) ? $value : $fallback;
    }

    /**
     * Normalize nullable decimal from UI/storage input.
     */
    public static function normalize_decimal_or_null(mixed $value, int $precision = 2): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (is_string($value)) {
            $value = preg_replace('/[^0-9.\-]/', '', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, $precision, '.', '');
    }


    /**
     * Acquire a best-effort option-based lock.
     *
     * @return array{acquired: bool, token?: string}
     */
    public static function acquire_lock(string $key, int $ttl_seconds = 1800): array
    {
        $option_name = self::LOCK_PREFIX . sanitize_key($key);
        $token = wp_generate_uuid4();
        $now = time();
        $payload = [
            'token'      => $token,
            'acquiredAt' => $now,
            'expiresAt'  => $now + max(60, $ttl_seconds),
            'hostname'   => function_exists('gethostname') ? (string) gethostname() : 'unknown',
            'pid'        => function_exists('getmypid') ? (int) getmypid() : 0,
        ];

        if (add_option($option_name, $payload, '', false)) {
            return ['acquired' => true, 'token' => $token];
        }

        $existing = get_option($option_name);
        if (is_array($existing) && isset($existing['expiresAt']) && (int) $existing['expiresAt'] < $now) {
            update_option($option_name, $payload, false);
            return ['acquired' => true, 'token' => $token];
        }

        return ['acquired' => false];
    }

    /**
     * Release a named option-based lock.
     */
    public static function release_lock(string $key): void
    {
        $option_name = self::LOCK_PREFIX . sanitize_key($key);
        delete_option($option_name);
    }

    /**
     * Render admin notice if environment does not meet requirements.
     */
    public static function render_incompatible_environment_notice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        $message = sprintf(
            /* translators: 1: minimum PHP version, 2: minimum WordPress version. */
            __('Stellar Overstock Sync requires PHP %1$s+ and WordPress %2$s+.', 'stellar-overstock-sync'),
            esc_html(SOS_MINIMUM_PHP_VERSION),
            esc_html(SOS_MINIMUM_WP_VERSION)
        );

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            wp_kses_post($message)
        );
    }
}
