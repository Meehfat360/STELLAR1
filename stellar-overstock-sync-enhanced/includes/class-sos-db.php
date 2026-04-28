<?php
/**
 * Database table and schema helpers.
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class SOS_DB
{
    /**
     * Table suffixes.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'price_map'     => 'os_price_map',
        'price_data'    => 'os_price_data',
        'price_updates' => 'os_price_updates',
        'price_jobs'    => 'os_price_jobs',
        'price_review'  => 'os_price_review',
    ];

    /**
     * Disallow instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Get fully-qualified table name.
     */
    public static function table(string $key): string
    {
        global $wpdb;

        if (! isset(self::TABLES[$key])) {
            throw new \InvalidArgumentException('Unknown table key: ' . $key);
        }

        return $wpdb->prefix . self::TABLES[$key];
    }

    /**
     * Return all table names keyed by alias.
     *
     * @return array<string, string>
     */
    public static function all_tables(): array
    {
        $tables = [];

        foreach (array_keys(self::TABLES) as $key) {
            $tables[$key] = self::table($key);
        }

        return $tables;
    }

    /**
     * Build schema SQL statements.
     *
     * @return array<int, string>
     */
    public static function schema_statements(): array
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $map_table = self::table('price_map');
        $data_table = self::table('price_data');
        $updates_table = self::table('price_updates');
        $jobs_table = self::table('price_jobs');
        $review_table = self::table('price_review');

        return [
            "CREATE TABLE {$map_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                woo_product_id BIGINT UNSIGNED NOT NULL,
                source VARCHAR(30) NOT NULL DEFAULT 'overstock',
                source_item_id VARCHAR(120) NULL,
                source_url TEXT NOT NULL,
                sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
                profit_type VARCHAR(30) NOT NULL DEFAULT 'percent',
                profit_value DECIMAL(10,2) NOT NULL DEFAULT 20.00,
                min_price DECIMAL(10,2) NULL,
                max_price DECIMAL(10,2) NULL,
                price_rounding VARCHAR(30) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                last_checked_at DATETIME NULL,
                last_success_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY woo_product_id (woo_product_id),
                KEY sync_status_last_checked (sync_enabled, status, last_checked_at),
                KEY source_item_id (source_item_id)
            ) {$charset_collate};",
            "CREATE TABLE {$data_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_id BIGINT UNSIGNED NULL,
                map_id BIGINT UNSIGNED NOT NULL,
                woo_product_id BIGINT UNSIGNED NOT NULL,
                source_item_id VARCHAR(120) NULL,
                source_url TEXT NOT NULL,
                page_title TEXT NULL,
                collected_price DECIMAL(10,2) NULL,
                collected_currency VARCHAR(10) NULL,
                stock_status VARCHAR(30) NULL,
                scrape_status VARCHAR(20) NOT NULL,
                scrape_error TEXT NULL,
                http_status SMALLINT NULL,
                raw_payload LONGTEXT NULL,
                selector_version VARCHAR(40) NULL,
                scraped_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY map_scraped_at (map_id, scraped_at),
                KEY woo_scraped_at (woo_product_id, scraped_at),
                KEY scrape_status_scraped_at (scrape_status, scraped_at)
            ) {$charset_collate};",
            "CREATE TABLE {$updates_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_id BIGINT UNSIGNED NULL,
                map_id BIGINT UNSIGNED NOT NULL,
                woo_product_id BIGINT UNSIGNED NOT NULL,
                price_data_id BIGINT UNSIGNED NULL,
                source_price DECIMAL(10,2) NULL,
                computed_store_price DECIMAL(10,2) NULL,
                old_woo_price DECIMAL(10,2) NULL,
                new_woo_price DECIMAL(10,2) NULL,
                update_status VARCHAR(20) NOT NULL,
                update_reason VARCHAR(100) NULL,
                update_error TEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY map_created_at (map_id, created_at),
                KEY woo_created_at (woo_product_id, created_at),
                KEY update_status_created_at (update_status, created_at)
            ) {$charset_collate};",
            "CREATE TABLE {$jobs_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_type VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL,
                total_items INT NOT NULL DEFAULT 0,
                success_count INT NOT NULL DEFAULT 0,
                fail_count INT NOT NULL DEFAULT 0,
                started_at DATETIME NOT NULL,
                ended_at DATETIME NULL,
                notes TEXT NULL,
                PRIMARY KEY  (id),
                KEY job_type_started_at (job_type, started_at),
                KEY status_started_at (status, started_at)
            ) {$charset_collate};",
            "CREATE TABLE {$review_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                map_id BIGINT UNSIGNED NOT NULL,
                woo_product_id BIGINT UNSIGNED NOT NULL,
                price_data_id BIGINT UNSIGNED NULL,
                source_price DECIMAL(10,2) NULL,
                computed_store_price DECIMAL(10,2) NULL,
                reason VARCHAR(100) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL,
                reviewed_at DATETIME NULL,
                PRIMARY KEY  (id),
                KEY status_created_at (status, created_at),
                KEY woo_status (woo_product_id, status)
            ) {$charset_collate};",
        ];
    }

    /**
     * Check whether all plugin tables exist.
     */
    public static function all_tables_exist(): bool
    {
        global $wpdb;

        foreach (self::all_tables() as $table_name) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
            if ($table_name !== $exists) {
                return false;
            }
        }

        return true;
    }
}
