<?php
/**
 * Installation, activation, and schema upgrades.
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class SOS_Installer
{
    /**
     * Option flag used to trigger post-activation redirect.
     */
    private const REDIRECT_OPTION = 'sos_do_activation_redirect';

    /**
     * Disallow instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Activation hook callback.
     */
    public static function activate(): void
    {
        if (! SOS_Utils::is_environment_compatible()) {
            deactivate_plugins(SOS_PLUGIN_BASENAME);

            wp_die(
                esc_html__('Stellar Overstock Sync cannot be activated because the server environment does not meet the minimum requirements.', 'stellar-overstock-sync'),
                esc_html__('Plugin Activation Error', 'stellar-overstock-sync'),
                ['back_link' => true]
            );
        }

        self::install_or_upgrade();
        add_option(self::REDIRECT_OPTION, '1');
    }

    /**
     * Deactivation hook callback.
     */
    public static function deactivate(): void
    {
        delete_option(self::REDIRECT_OPTION);
        wp_clear_scheduled_hook('sos_hourly_updater_event');
    }

    /**
     * Upgrade schema when stored version is behind current plugin version.
     */
    public static function maybe_upgrade(): void
    {
        $installed_version = (string) get_option(SOS_Utils::VERSION_OPTION, '0.0.0');

        if (version_compare($installed_version, SOS_VERSION, '<') || ! SOS_DB::all_tables_exist()) {
            self::install_or_upgrade();
        }
    }

    /**
     * Run schema creation and seed defaults.
     */
    public static function install_or_upgrade(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $wpdb->hide_errors();

        foreach (SOS_DB::schema_statements() as $statement) {
            dbDelta($statement);
        }

        self::seed_default_options();
        update_option(SOS_Utils::VERSION_OPTION, SOS_VERSION, false);
    }

    /**
     * Seed settings that Stage 1 depends on.
     */
    private static function seed_default_options(): void
    {
        $defaults = [
            'sos_settings' => [
                'freshness_window_hours' => 72,
                'batch_size'             => 25,
                'request_timeout'        => 20,
                'request_retries'        => 2,
                'request_delay_seconds'  => 1,
                'default_currency'       => 'USD',
                'shock_threshold_pct'    => 25,
                'stop_after_failures'    => 10,
                'dry_run_mode'           => 1,
                'auto_update_enabled'    => 0,
                'zyte_enabled'           => 0,
                'zyte_api_key'           => '',
                'zyte_mode'              => 'browser_html',
            ],
        ];

        foreach ($defaults as $option_name => $default_value) {
            if (false === get_option($option_name, false)) {
                add_option($option_name, $default_value, '', false);
            }
        }
    }
}
