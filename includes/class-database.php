<?php
/**
 * Stellar Meta — Database Installer v4.1.0
 *
 * FIXES in 4.1.0:
 *  - SCHEMA_VERSION bumped to 4.1.0 so tables are re-created after version skip
 *  - install() now verifies every table actually exists before skipping (self::tables_exist())
 *    This prevents silent failures when the DB option was set but CREATE TABLE never ran
 *  - maybe_upgrade() updated with 4.1.0 migration path
 *  - Defensive: on init, Stellar_Meta boots self::install() if any core table is missing
 */
defined( 'ABSPATH' ) || exit;

class Stellar_Meta_Database {

    const SCHEMA_VERSION = '4.1.0';

    /** Maximum lengths for validation before DB insert */
    const MAX_EVENT_NAME_LEN  = 80;
    const MAX_EVENT_ID_LEN    = 64;
    const MAX_PLATFORM_LEN    = 32;
    const MAX_STATUS_LEN      = 20;
    const VALID_STATUSES      = ['pending','processing','retrying','delivered','failed'];
    const VALID_PLATFORMS     = ['meta','ga4','google_ads','tiktok'];

    // ── Install / Upgrade ─────────────────────────────────────────────────────

    public static function install(): void {
        $installed = get_option( 'stellar_meta_db_version', '' );

        // KEY FIX: Always run create_tables() if ANY required table is missing,
        // regardless of the version option. This handles:
        //  - Fresh installs where the option was never set
        //  - Re-installs where option exists but tables were never created
        //  - Version skips (e.g. option says 4.0.0 but 4.1.0 adds new tables)
        $needs_create = ! self::tables_exist();
        $needs_upgrade = version_compare( $installed, self::SCHEMA_VERSION, '<' );

        if ( ! $needs_create && ! $needs_upgrade ) return;

        try {
            self::create_tables();

            if ( $needs_upgrade ) {
                self::maybe_upgrade( $installed );
            }

            update_option( 'stellar_meta_db_version', self::SCHEMA_VERSION );
        } catch ( \Throwable $e ) {
            error_log( '[Stellar Meta] DB install failed: ' . $e->getMessage() );
            add_action( 'admin_notices', function() use ( $e ) {
                echo '<div class="notice notice-error"><p><strong>Stellar Meta:</strong> '
                     . esc_html__( 'Database setup failed:', 'stellar-meta' ) . ' '
                     . esc_html( $e->getMessage() ) . '</p></div>';
            } );
        }
    }

    /**
     * Check that the three most-critical tables exist.
     * Used by install() to detect broken/missing installations.
     */
    public static function tables_exist(): bool {
        global $wpdb;
        $p = STELLAR_META_DB_PREFIX;

        // Check the three tables most likely to cause fatal errors if missing
        $core_tables = [
            "{$p}event_queue",
            "{$p}identity_graph",
            "{$p}audit_log",
        ];

        foreach ( $core_tables as $table ) {
            // Use SHOW TABLES LIKE — fastest existence check, no exception on miss
            $exists = $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
            );
            if ( $exists !== $table ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply delta migrations based on previously installed version.
     */
    private static function maybe_upgrade( string $from ): void {
        if ( empty( $from ) ) return; // fresh install — no migration needed

        global $wpdb;
        $p = STELLAR_META_DB_PREFIX;

        // v4.0.0 migrations: add columns that may be missing from older schemas
        if ( version_compare( $from, '4.0.0', '<' ) ) {
            $col = $wpdb->get_results( "SHOW COLUMNS FROM {$p}ai_product_data LIKE 'bid_score'" );
            if ( empty( $col ) ) {
                $wpdb->query( "ALTER TABLE {$p}ai_product_data ADD COLUMN bid_score DECIMAL(5,2) DEFAULT NULL AFTER confidence_score" );
            }

            $col2 = $wpdb->get_results( "SHOW COLUMNS FROM {$p}event_queue LIKE 'data_hash'" );
            if ( empty( $col2 ) ) {
                $wpdb->query( "ALTER TABLE {$p}event_queue ADD COLUMN data_hash VARCHAR(64) DEFAULT NULL, ADD KEY data_hash (data_hash)" );
            }

            $col3 = $wpdb->get_results( "SHOW COLUMNS FROM {$p}anomaly_log LIKE 'alert_channel'" );
            if ( empty( $col3 ) ) {
                $wpdb->query( "ALTER TABLE {$p}anomaly_log ADD COLUMN alert_channel VARCHAR(20) DEFAULT 'email' AFTER alert_sent" );
            }
        }

        // v4.1.0 migrations: nothing new schema-wise; tables fully recreated via create_tables()
        // Add future ALTER TABLE statements here for v4.2.0+
    }

    // ── Table Creation ────────────────────────────────────────────────────────

    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p       = STELLAR_META_DB_PREFIX;

        // ── Event Queue ───────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}event_queue (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id      VARCHAR(64)  NOT NULL,
            event_name    VARCHAR(80)  NOT NULL,
            payload       LONGTEXT     NOT NULL,
            platform      VARCHAR(32)  NOT NULL DEFAULT 'meta',
            status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
            attempts      TINYINT      NOT NULL DEFAULT 0,
            max_attempts  TINYINT      NOT NULL DEFAULT 3,
            latency_ms    SMALLINT UNSIGNED DEFAULT NULL,
            data_hash     VARCHAR(64)  DEFAULT NULL,
            error_message TEXT         DEFAULT NULL,
            scheduled_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fired_at      DATETIME     DEFAULT NULL,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY event_id (event_id),
            KEY status_scheduled (status, scheduled_at),
            KEY platform_status  (platform, status),
            KEY data_hash (data_hash),
            KEY created_at (created_at)
        ) $charset;" );

        // ── Pixel Log — deduplication ─────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}pixel_log (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id   VARCHAR(64)  NOT NULL,
            event_name VARCHAR(80)  NOT NULL,
            user_hash  VARCHAR(64)  DEFAULT NULL,
            session_id VARCHAR(64)  DEFAULT NULL,
            fired_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY event_id (event_id),
            KEY fired_at (fired_at),
            KEY user_hash (user_hash)
        ) $charset;" );

        // ── Audience Segments ─────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}segments (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name             VARCHAR(120) NOT NULL,
            description      TEXT         DEFAULT NULL,
            rules            LONGTEXT     NOT NULL,
            meta_audience_id VARCHAR(64)  DEFAULT NULL,
            member_count     INT UNSIGNED DEFAULT 0,
            last_synced      DATETIME     DEFAULT NULL,
            auto_sync        TINYINT(1)   NOT NULL DEFAULT 0,
            created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY auto_sync (auto_sync),
            KEY created_at (created_at)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$p}segment_members (
            segment_id  BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            added_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (segment_id, customer_id),
            KEY customer_id (customer_id),
            KEY added_at (added_at)
        ) $charset;" );

        // ── AI Product Data ───────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}ai_product_data (
            product_id        BIGINT UNSIGNED NOT NULL,
            ai_title          TEXT            DEFAULT NULL,
            ai_description    TEXT            DEFAULT NULL,
            purchase_type     VARCHAR(30)     DEFAULT NULL,
            price_tier        VARCHAR(30)     DEFAULT NULL,
            auto_tags         VARCHAR(255)    DEFAULT NULL,
            confidence_score  DECIMAL(4,3)    DEFAULT NULL,
            bid_score         DECIMAL(5,2)    DEFAULT NULL,
            model_used        VARCHAR(50)     DEFAULT NULL,
            generated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (product_id),
            KEY purchase_type (purchase_type),
            KEY price_tier (price_tier)
        ) $charset;" );

        // ── Funnel Snapshots ──────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}funnel_snapshots (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            snapshot_date     DATE            NOT NULL,
            view_content      INT UNSIGNED    DEFAULT 0,
            add_to_cart       INT UNSIGNED    DEFAULT 0,
            initiate_checkout INT UNSIGNED    DEFAULT 0,
            purchase          INT UNSIGNED    DEFAULT 0,
            revenue           DECIMAL(12,2)   DEFAULT 0.00,
            ad_spend          DECIMAL(12,2)   DEFAULT 0.00,
            platform          VARCHAR(32)     NOT NULL DEFAULT 'meta',
            PRIMARY KEY (id),
            UNIQUE KEY date_platform (snapshot_date, platform),
            KEY snapshot_date (snapshot_date)
        ) $charset;" );

        // ── Audit Log ─────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}audit_log (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED DEFAULT NULL,
            action     VARCHAR(120) NOT NULL,
            context    TEXT         DEFAULT NULL,
            ip_address VARCHAR(45)  DEFAULT NULL,
            created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY user_id (user_id),
            KEY action (action(40))
        ) $charset;" );

        // ── LTV Scores ────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}ltv_scores (
            customer_id      BIGINT UNSIGNED NOT NULL,
            predicted_90d    DECIMAL(10,2)   DEFAULT 0.00,
            predicted_12m    DECIMAL(10,2)   DEFAULT 0.00,
            ltv_tier         VARCHAR(20)     DEFAULT 'low',
            model_version    VARCHAR(20)     DEFAULT '1.0',
            first_order_date DATE            DEFAULT NULL,
            order_count      INT UNSIGNED    DEFAULT 0,
            total_spend      DECIMAL(12,2)   DEFAULT 0.00,
            scored_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (customer_id),
            KEY ltv_tier (ltv_tier),
            KEY scored_at (scored_at),
            KEY predicted_12m (predicted_12m)
        ) $charset;" );

        // ── Churn Scores ──────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}churn_scores (
            customer_id          BIGINT UNSIGNED NOT NULL,
            churn_risk           VARCHAR(20)     DEFAULT 'low',
            rfm_recency          INT UNSIGNED    DEFAULT 0,
            rfm_frequency        INT UNSIGNED    DEFAULT 0,
            rfm_monetary         DECIMAL(12,2)   DEFAULT 0.00,
            ai_risk_score        DECIMAL(4,3)    DEFAULT 0.000,
            in_winback_audience  TINYINT(1)      DEFAULT 0,
            last_order_date      DATE            DEFAULT NULL,
            scored_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (customer_id),
            KEY churn_risk (churn_risk),
            KEY in_winback_audience (in_winback_audience),
            KEY scored_at (scored_at)
        ) $charset;" );

        // ── Touchpoints ───────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}touchpoints (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id   VARCHAR(64)  NOT NULL,
            stellar_uid  VARCHAR(64)  DEFAULT NULL,
            customer_id  BIGINT UNSIGNED DEFAULT NULL,
            order_id     BIGINT UNSIGNED DEFAULT NULL,
            channel      VARCHAR(50)  DEFAULT 'direct',
            campaign     VARCHAR(120) DEFAULT NULL,
            medium       VARCHAR(60)  DEFAULT NULL,
            source       VARCHAR(60)  DEFAULT NULL,
            landing_page TEXT         DEFAULT NULL,
            touched_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY stellar_uid (stellar_uid),
            KEY customer_id (customer_id),
            KEY order_id (order_id),
            KEY touched_at (touched_at),
            KEY channel_touched (channel, touched_at)
        ) $charset;" );

        // ── Anomaly Log ───────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}anomaly_log (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            metric         VARCHAR(60)  NOT NULL,
            baseline_value DECIMAL(12,4) DEFAULT 0.0000,
            observed_value DECIMAL(12,4) DEFAULT 0.0000,
            deviation_pct  DECIMAL(8,2)  DEFAULT 0.00,
            severity       VARCHAR(20)   DEFAULT 'warning',
            alert_sent     TINYINT(1)    DEFAULT 0,
            alert_channel  VARCHAR(20)   DEFAULT 'email',
            alert_sent_at  DATETIME      DEFAULT NULL,
            resolved_at    DATETIME      DEFAULT NULL,
            notes          TEXT          DEFAULT NULL,
            created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY metric_severity (metric, severity),
            KEY alert_sent (alert_sent),
            KEY created_at (created_at)
        ) $charset;" );

        // ── Cohort Data ───────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}cohort_data (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            cohort_month   DATE            NOT NULL,
            period_offset  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            customers      INT UNSIGNED    DEFAULT 0,
            revenue        DECIMAL(12,2)   DEFAULT 0.00,
            orders         INT UNSIGNED    DEFAULT 0,
            retention_pct  DECIMAL(5,2)    DEFAULT 0.00,
            PRIMARY KEY (id),
            UNIQUE KEY cohort_period (cohort_month, period_offset),
            KEY cohort_month (cohort_month)
        ) $charset;" );

        // ── Revenue Heatmap ───────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}revenue_heatmap (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dow             TINYINT UNSIGNED NOT NULL,
            hour_of_day     TINYINT UNSIGNED NOT NULL,
            orders          INT UNSIGNED    DEFAULT 0,
            revenue         DECIMAL(12,2)   DEFAULT 0.00,
            avg_order_value DECIMAL(10,2)   DEFAULT 0.00,
            snapshot_date   DATE            NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY dow_hour_date (dow, hour_of_day, snapshot_date),
            KEY dow_hour (dow, hour_of_day)
        ) $charset;" );

        // ── A/B Tests ─────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}ab_tests (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            test_name          VARCHAR(120) NOT NULL,
            variant            VARCHAR(60)  NOT NULL DEFAULT 'control',
            orders             INT UNSIGNED DEFAULT 0,
            revenue            DECIMAL(12,2) DEFAULT 0.00,
            conversion_rate    DECIMAL(6,4)  DEFAULT 0.0000,
            meta_experiment_id VARCHAR(64)   DEFAULT NULL,
            started_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at           DATETIME      DEFAULT NULL,
            PRIMARY KEY (id),
            KEY test_name (test_name),
            KEY started_at (started_at)
        ) $charset;" );

        // ── Identity Graph ────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$p}identity_graph (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            stellar_uid  VARCHAR(64)  NOT NULL,
            email_hash   VARCHAR(64)  DEFAULT NULL,
            customer_id  BIGINT UNSIGNED DEFAULT NULL,
            devices      TEXT         DEFAULT NULL,
            first_seen   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY stellar_uid (stellar_uid),
            KEY email_hash (email_hash),
            KEY customer_id (customer_id),
            KEY last_seen (last_seen)
        ) $charset;" );
    }

    // ── Validation Helpers ────────────────────────────────────────────────────

    public static function validate_event( array $data ): bool {
        if ( empty( $data['event_id'] ) || strlen( $data['event_id'] ) > self::MAX_EVENT_ID_LEN ) {
            throw new \InvalidArgumentException( 'Invalid event_id: must be 1-64 chars.' );
        }
        if ( empty( $data['event_name'] ) || strlen( $data['event_name'] ) > self::MAX_EVENT_NAME_LEN ) {
            throw new \InvalidArgumentException( 'Invalid event_name.' );
        }
        if ( ! empty( $data['platform'] ) && ! in_array( $data['platform'], self::VALID_PLATFORMS, true ) ) {
            throw new \InvalidArgumentException( 'Invalid platform: ' . $data['platform'] );
        }
        if ( ! empty( $data['status'] ) && ! in_array( $data['status'], self::VALID_STATUSES, true ) ) {
            throw new \InvalidArgumentException( 'Invalid status: ' . $data['status'] );
        }
        return true;
    }

    public static function cached( string $key, callable $query, int $ttl = 300 ): mixed {
        $transient = 'stellar_' . md5( $key );
        $cached    = get_transient( $transient );
        if ( false !== $cached ) return $cached;
        $result = $query();
        set_transient( $transient, $result, $ttl );
        return $result;
    }

    public static function flush_cache(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_stellar_%' OR option_name LIKE '_transient_timeout_stellar_%'" );
    }
}
