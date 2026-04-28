<?php
/**
 * Plugin Name: Stellar Overstock Sync
 * Plugin URI:  https://stellarsavers.com/
 * Description: Enterprise-grade foundation for syncing Overstock product prices into WooCommerce with Chrome extension discovery, automatic mapping, and a secure collector/updater architecture.
 * Version: 0.7.0
 * Author:      OpenAI
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: stellar-overstock-sync
 * Domain Path: /languages
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('SOS_VERSION')) {
    define('SOS_VERSION', '0.7.0');
}

if (! defined('SOS_PLUGIN_FILE')) {
    define('SOS_PLUGIN_FILE', __FILE__);
}

if (! defined('SOS_PLUGIN_BASENAME')) {
    define('SOS_PLUGIN_BASENAME', plugin_basename(__FILE__));
}

if (! defined('SOS_PLUGIN_DIR')) {
    define('SOS_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (! defined('SOS_PLUGIN_URL')) {
    define('SOS_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (! defined('SOS_MINIMUM_WP_VERSION')) {
    define('SOS_MINIMUM_WP_VERSION', '6.4');
}

if (! defined('SOS_MINIMUM_PHP_VERSION')) {
    define('SOS_MINIMUM_PHP_VERSION', '8.1');
}

require_once SOS_PLUGIN_DIR . 'includes/class-sos-utils.php';
require_once SOS_PLUGIN_DIR . 'includes/class-sos-db.php';
require_once SOS_PLUGIN_DIR . 'includes/class-sos-installer.php';
require_once SOS_PLUGIN_DIR . 'includes/class-sos-admin.php';
require_once SOS_PLUGIN_DIR . 'includes/class-sos-mapper.php';
require_once SOS_PLUGIN_DIR . 'includes/class-sos-logger.php';
require_once SOS_PLUGIN_DIR . 'includes/class-sos-collector.php';
require_once SOS_PLUGIN_DIR . 'includes/class-sos-rules.php';
require_once SOS_PLUGIN_DIR . 'includes/class-sos-updater.php';
require_once SOS_PLUGIN_DIR . 'includes/class-sos-rest-api.php';

final class SOS_Plugin
{
    /**
     * Singleton instance.
     *
     * @var SOS_Plugin|null
     */
    private static ?SOS_Plugin $instance = null;

    /**
     * Get singleton instance.
     */
    public static function instance(): SOS_Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Boot plugin hooks.
     */
    public function boot(): void
    {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('plugins_loaded', [$this, 'maybe_initialize']);
    }

    /**
     * Prevent direct construction.
     */
    private function __construct()
    {
    }

    /**
     * Prevent cloning.
     */
    private function __clone()
    {
    }

    /**
     * Prevent unserialization.
     */
    public function __wakeup(): void
    {
        throw new \RuntimeException('Cannot unserialize singleton.');
    }

    /**
     * Load translations.
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain('stellar-overstock-sync', false, dirname(SOS_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Initialize plugin only when environment is compatible.
     */
    public function maybe_initialize(): void
    {
        if (! SOS_Utils::is_environment_compatible()) {
            add_action('admin_notices', [SOS_Utils::class, 'render_incompatible_environment_notice']);
            return;
        }

        SOS_Installer::maybe_upgrade();
        SOS_Admin::instance()->register();
        add_action('sos_hourly_updater_event', [self::class, 'run_scheduled_updater']);
        add_action('rest_api_init', ['SOS_Rest_API', 'register_routes']);
        $this->maybe_schedule_events();
    }

    /**
     * Ensure plugin cron events are scheduled.
     */
    private function maybe_schedule_events(): void
    {
        if (! wp_next_scheduled('sos_hourly_updater_event')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sos_hourly_updater_event');
        }
    }

    /**
     * Run scheduled updater callback.
     */
    public static function run_scheduled_updater(): void
    {
        SOS_Updater::maybe_run_scheduled_update();
    }
}

register_activation_hook(SOS_PLUGIN_FILE, ['SOS_Installer', 'activate']);
register_deactivation_hook(SOS_PLUGIN_FILE, ['SOS_Installer', 'deactivate']);

SOS_Plugin::instance()->boot();
