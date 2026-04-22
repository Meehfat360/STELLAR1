<?php
/**
 * Plugin Name:       Stellar Meta — Enterprise Facebook & Instagram Integration
 * Plugin URI:        https://stellarsavers.com
 * Description:       Enterprise-grade Meta + Google integration for WooCommerce. v4.1.0: Full GDPR compliance — cookie consent banner (Accept/Decline/Manage), Google Consent Mode v2, GPC/DNT detection, consent audit log, data retention cron, third-party disclosures, credential validation, right-to-erasure, duplicate defined() removed.
 * Version:           4.1.0
 * Author:            Stellar Savers
 * License:           GPL-2.0+
 * Text Domain:       stellar-meta
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 */
defined( 'ABSPATH' ) || exit;

define( 'STELLAR_META_VERSION',   '4.1.0' );
define( 'STELLAR_META_FILE',      __FILE__ );
define( 'STELLAR_META_DIR',       plugin_dir_path( __FILE__ ) );
define( 'STELLAR_META_URL',       plugin_dir_url( __FILE__ ) );
define( 'STELLAR_META_BASENAME',  plugin_basename( __FILE__ ) );
define( 'STELLAR_META_DB_PREFIX', 'stellar_meta_' );

spl_autoload_register( function ( string $class ): void {
    $prefix = 'StellarMeta\\';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) return;
    $relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, strlen( $prefix ) ) );
    $map = [
        'Admin'        => 'admin',
        'API'          => 'includes/api',
        'Tracking'     => 'includes/tracking',
        'Audience'     => 'includes/audience',
        'Catalog'      => 'includes/catalog',
        'Privacy'      => 'includes/privacy',
        'Analytics'    => 'includes/analytics',
        'AI'           => 'includes/ai',
        'Integrations' => 'includes/integrations',
        'Core'         => 'includes',
    ];
    foreach ( $map as $ns => $dir ) {
        if ( strncmp( $ns . DIRECTORY_SEPARATOR, $relative, strlen( $ns ) + 1 ) === 0 ) {
            $file = STELLAR_META_DIR . $dir . DIRECTORY_SEPARATOR .
                    'class-' . strtolower( str_replace( [ $ns . DIRECTORY_SEPARATOR, '_' ], [ '', '-' ], $relative ) ) . '.php';
            if ( file_exists( $file ) ) { require_once $file; return; }
        }
    }
} );

final class Stellar_Meta {
    private static ?self $instance = null;
    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', fn() => printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Stellar Meta requires WooCommerce.', 'stellar-meta' ) ) );
            return;
        }
        load_plugin_textdomain( 'stellar-meta', false, dirname( STELLAR_META_BASENAME ) . '/languages' );
        add_action( 'plugins_loaded', [ $this, 'init' ], 20 );
        register_activation_hook( STELLAR_META_FILE,   [ $this, 'activate' ] );
        register_deactivation_hook( STELLAR_META_FILE, [ $this, 'deactivate' ] );
    }

    public function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) return;

        // Core
        require_once STELLAR_META_DIR . 'includes/class-database.php';
        require_once STELLAR_META_DIR . 'includes/class-settings.php';
        require_once STELLAR_META_DIR . 'includes/class-logger.php';

        // Defensive DB check: if tables are missing (e.g. version-skip on activation,
        // manual DB reset, or multisite new-site creation), create them now before
        // any class tries to query them.
        Stellar_Meta_Database::install();

        // Tracking
        require_once STELLAR_META_DIR . 'includes/tracking/class-deduplicator.php';
        require_once STELLAR_META_DIR . 'includes/tracking/class-event-builder.php';
        require_once STELLAR_META_DIR . 'includes/tracking/class-event-queue.php';
        require_once STELLAR_META_DIR . 'includes/tracking/class-capi.php';
        require_once STELLAR_META_DIR . 'includes/tracking/class-pixel.php';
        require_once STELLAR_META_DIR . 'includes/tracking/class-ga4-server.php';
        require_once STELLAR_META_DIR . 'includes/tracking/class-ga4-eec.php';
        require_once STELLAR_META_DIR . 'includes/tracking/class-gads-conversions.php';
        require_once STELLAR_META_DIR . 'includes/tracking/class-behavioural-events.php';
        require_once STELLAR_META_DIR . 'includes/tracking/class-identity-graph.php';

        // AI
        require_once STELLAR_META_DIR . 'includes/ai/class-enrichment.php';
        require_once STELLAR_META_DIR . 'includes/ai/class-ltv-predictor.php';
        require_once STELLAR_META_DIR . 'includes/ai/class-churn-predictor.php';
        require_once STELLAR_META_DIR . 'includes/ai/class-bid-scorer.php';

        // Analytics
        require_once STELLAR_META_DIR . 'includes/analytics/class-funnel-analytics.php';
        require_once STELLAR_META_DIR . 'includes/analytics/class-roas-tracker.php';
        require_once STELLAR_META_DIR . 'includes/analytics/class-cohort-analytics.php';
        require_once STELLAR_META_DIR . 'includes/analytics/class-revenue-heatmap.php';
        require_once STELLAR_META_DIR . 'includes/analytics/class-anomaly-detector.php';
        require_once STELLAR_META_DIR . 'includes/analytics/class-product-analytics.php'; // NEW
        require_once STELLAR_META_DIR . 'includes/analytics/class-live-stats.php';         // NEW

        // Audience + services
        require_once STELLAR_META_DIR . 'includes/audience/class-segment-builder.php';
        require_once STELLAR_META_DIR . 'includes/combined-services.php';
        require_once STELLAR_META_DIR . 'includes/class-feed-endpoint.php';

        // Admin
        if ( is_admin() ) {
            require_once STELLAR_META_DIR . 'admin/class-admin.php';
            Stellar_Meta_Admin::instance();
        }

        // Boot singletons
        Stellar_Meta_Settings::instance();
        Stellar_Meta_Pixel::instance();
        Stellar_Meta_Event_Queue::instance();
        Stellar_Meta_Consent_Manager::instance();
        Stellar_Meta_GA4_Server::instance();
        Stellar_Meta_GA4_EEC::instance();
        Stellar_Meta_GAds_Conversions::instance();
        Stellar_Meta_Behavioural_Events::instance();
        Stellar_Meta_Identity_Graph::instance();
        Stellar_Meta_Anomaly_Detector::instance();

        do_action( 'stellar_meta_loaded' );
    }

    public function activate(): void {
        require_once STELLAR_META_DIR . 'includes/class-database.php';
        require_once STELLAR_META_DIR . 'includes/class-settings.php';
        Stellar_Meta_Database::install();
        $crons = [
            'stellar_meta_process_queue'    => 'every_minute',
            'stellar_meta_sync_catalog'     => 'daily',
            'stellar_meta_score_ltv'        => 'twicedaily',
            'stellar_meta_score_churn'      => 'daily',
            'stellar_meta_detect_anomalies' => 'every_15_min',
            'stellar_meta_update_heatmap'   => 'daily',
            'stellar_meta_build_cohorts'    => 'daily',
            'stellar_meta_enforce_retention'=> 'daily',   // GDPR data retention enforcement
        ];
        foreach ( $crons as $hook => $freq ) {
            if ( ! wp_next_scheduled( $hook ) ) wp_schedule_event( time(), $freq, $hook );
        }
        flush_rewrite_rules();
    }

    public function deactivate(): void {
        foreach ( [
            'stellar_meta_process_queue','stellar_meta_sync_audiences','stellar_meta_sync_catalog',
            'stellar_meta_score_ltv','stellar_meta_score_churn','stellar_meta_detect_anomalies',
            'stellar_meta_update_heatmap','stellar_meta_build_cohorts','stellar_meta_enforce_retention',
        ] as $h ) wp_clear_scheduled_hook( $h );
        flush_rewrite_rules();
    }
}

add_filter( 'cron_schedules', function( array $s ): array {
    $s['every_minute'] = [ 'interval' => 60,  'display' => __( 'Every Minute',     'stellar-meta' ) ];
    $s['every_15_min'] = [ 'interval' => 900, 'display' => __( 'Every 15 Minutes', 'stellar-meta' ) ];
    return $s;
} );

add_action( 'stellar_meta_process_queue',    [ 'Stellar_Meta_Event_Queue',       'process'       ] );
add_action( 'stellar_meta_sync_audiences',   [ 'Stellar_Meta_Meta_Audience_Sync','run_scheduled' ] );
add_action( 'stellar_meta_sync_catalog',     [ 'Stellar_Meta_Feed_Generator',    'run_scheduled' ] );
add_action( 'stellar_meta_score_ltv',        [ 'Stellar_Meta_LTV_Predictor',     'run_batch'     ] );
add_action( 'stellar_meta_score_churn',      [ 'Stellar_Meta_Churn_Predictor',   'run_batch'     ] );
add_action( 'stellar_meta_detect_anomalies', [ 'Stellar_Meta_Anomaly_Detector',  'run'           ] );
add_action( 'stellar_meta_update_heatmap',   [ 'Stellar_Meta_Revenue_Heatmap',   'rebuild'       ] );
add_action( 'stellar_meta_build_cohorts',    [ 'Stellar_Meta_Cohort_Analytics',  'rebuild'       ] );
add_action( 'stellar_meta_enforce_retention', function() {
    Stellar_Meta_Consent_Manager::instance()->enforce_retention();
} );

add_action( 'plugins_loaded', fn() => Stellar_Meta::instance(), 10 );

add_action( 'before_woocommerce_init', function(): void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables',  STELLAR_META_FILE, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', STELLAR_META_FILE, true );
    }
} );
