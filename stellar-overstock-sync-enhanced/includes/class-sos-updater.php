<?php
/**
 * Manual/safe WooCommerce updater.
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class SOS_Updater
{
    /**
     * Disallow instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Run scheduled updater if enabled.
     *
     * @return array<string, mixed>
     */
    public static function maybe_run_scheduled_update(): array
    {
        $settings = (array) get_option('sos_settings', []);
        if (empty($settings['auto_update_enabled'])) {
            return ['success' => false, 'message' => 'Auto-update is disabled.'];
        }

        return self::run_bulk_update('scheduled');
    }

    /**
     * Run manual bulk update for all safe, enabled, active mappings.
     *
     * @return array<string, mixed>
     */
    public static function bulk_update_safe_mappings(): array
    {
        return self::run_bulk_update('manual_bulk');
    }

    /**
     * Run update for a single mapping.
     *
     * @return array<string, mixed>
     */
    public static function update_single_mapping(int $mapping_id, bool $bypass_shock = false): array
    {
        $mapping = SOS_Mapper::get_by_id($mapping_id);
        if (! $mapping) {
            return ['success' => false, 'message' => 'Mapping not found.', 'update_status' => 'failed'];
        }

        $settings = (array) get_option('sos_settings', []);
        $latest = self::get_latest_successful_price_data($mapping_id);
        if (! $latest) {
            self::log_update($mapping, null, null, null, null, 'skipped', 'no_successful_source_record', null);
            return ['success' => false, 'message' => 'No successful collected source record was found for this mapping.', 'update_status' => 'skipped'];
        }

        $freshness_hours = isset($settings['freshness_window_hours']) ? max(1, (int) $settings['freshness_window_hours']) : 72;
        if (! self::is_fresh_enough((string) $latest['scraped_at'], $freshness_hours)) {
            self::log_update($mapping, $latest, null, null, null, 'skipped', 'stale_source_record', null);
            return ['success' => false, 'message' => 'Latest collected source record is older than the freshness window.', 'update_status' => 'skipped'];
        }

        $source_price = isset($latest['collected_price']) ? (float) $latest['collected_price'] : 0.0;
        if ($source_price <= 0) {
            self::log_update($mapping, $latest, null, null, null, 'skipped', 'invalid_source_price', null);
            return ['success' => false, 'message' => 'Collected source price is invalid.', 'update_status' => 'skipped'];
        }

        $computed = SOS_Rules::compute_store_price($source_price, $mapping);
        $bounds = SOS_Rules::validate_bounds($computed, $mapping);
        if (! $bounds['ok']) {
            self::log_update($mapping, $latest, $source_price, $computed, null, 'skipped', (string) $bounds['reason'], null);
            return ['success' => false, 'message' => 'Computed price failed configured min/max rules.', 'update_status' => 'skipped'];
        }

        $previous_source_price = self::get_previous_successful_source_price($mapping_id, (int) $latest['id']);
        $shock_threshold = isset($settings['shock_threshold_pct']) ? (float) $settings['shock_threshold_pct'] : 25.0;
        if (! $bypass_shock && SOS_Rules::exceeds_shock_threshold($source_price, $previous_source_price, $shock_threshold)) {
            self::insert_review_item($mapping, $latest, $source_price, $computed, 'shock_threshold_exceeded');
            self::log_update($mapping, $latest, $source_price, $computed, null, 'review', 'shock_threshold_exceeded', null);
            return ['success' => false, 'message' => 'Source price movement exceeded the shock threshold. Sent to review queue.', 'update_status' => 'review'];
        }

        if (! function_exists('wc_get_product')) {
            self::log_update($mapping, $latest, $source_price, $computed, null, 'failed', 'woocommerce_not_available', 'WooCommerce is not loaded.');
            return ['success' => false, 'message' => 'WooCommerce is not available.', 'update_status' => 'failed'];
        }

        $product = wc_get_product((int) $mapping['woo_product_id']);
        if (! $product) {
            self::log_update($mapping, $latest, $source_price, $computed, null, 'failed', 'product_not_found', 'WooCommerce product could not be loaded.');
            return ['success' => false, 'message' => 'WooCommerce product could not be loaded.', 'update_status' => 'failed'];
        }

        $old_price = (float) $product->get_regular_price();
        $new_price = number_format($computed, 2, '.', '');
        $product_type = $product->get_type();

        if (! empty($settings['dry_run_mode'])) {
            self::log_update($mapping, $latest, $source_price, $computed, $old_price, 'dry_run', 'dry_run_mode_' . sanitize_key($product_type), null);
            return [
                'success'   => true,
                'message'   => 'Dry-run mode is enabled. Price was validated but not saved.',
                'old_price' => $old_price,
                'new_price' => $new_price,
                'update_status' => 'dry_run',
            ];
        }

        try {
            self::apply_price_to_product($product, $new_price);
        } catch (\Throwable $throwable) {
            self::log_update($mapping, $latest, $source_price, $computed, $old_price, 'failed', 'save_failed_' . sanitize_key($product_type), $throwable->getMessage());
            return ['success' => false, 'message' => 'Failed to save WooCommerce product price.', 'update_status' => 'failed'];
        }

        self::log_update($mapping, $latest, $source_price, $computed, $old_price, 'updated', 'manual_single_update_' . sanitize_key($product_type), null);

        return [
            'success'   => true,
            'message'   => 'WooCommerce product price updated successfully.',
            'old_price' => $old_price,
            'new_price' => $new_price,
            'update_status' => 'updated',
        ];
    }

    /**
     * Get latest update status rows for mapping IDs.
     *
     * @param array<int, int> $mapping_ids Mapping IDs.
     * @return array<int, array<string, mixed>>
     */
    public static function get_latest_update_statuses(array $mapping_ids): array
    {
        global $wpdb;

        $mapping_ids = array_values(array_unique(array_filter(array_map('absint', $mapping_ids))));
        if ([] === $mapping_ids) {
            return [];
        }

        $table = SOS_DB::table('price_updates');
        $placeholders = implode(',', array_fill(0, count($mapping_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT u.* FROM {$table} u
             INNER JOIN (
                SELECT map_id, MAX(id) AS max_id
                FROM {$table}
                WHERE map_id IN ({$placeholders})
                GROUP BY map_id
             ) latest ON latest.max_id = u.id",
            $mapping_ids
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        $statuses = [];
        foreach ($rows as $row) {
            $statuses[(int) $row['map_id']] = $row;
        }

        return $statuses;
    }

    /**
     * Get paginated review queue rows.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, per_page: int, page: int}
     */
    public static function get_paginated_review_queue(string $status = 'pending', int $page = 1, int $per_page = 20): array
    {
        global $wpdb;

        $table = SOS_DB::table('price_review');
        $page = max(1, $page);
        $per_page = max(1, min(100, $per_page));
        $offset = ($page - 1) * $per_page;
        $status = sanitize_key($status);

        $allowed_statuses = ['pending', 'approved', 'rejected', ''];
        if (! in_array($status, $allowed_statuses, true)) {
            $status = 'pending';
        }

        $where_sql = '' === $status ? '1=1' : $wpdb->prepare('status = %s', $status);
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}");
        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        $items = $wpdb->get_results($query, ARRAY_A);

        return [
            'items'    => is_array($items) ? $items : [],
            'total'    => $total,
            'per_page' => $per_page,
            'page'     => $page,
        ];
    }

    /**
     * Get paginated logs.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, per_page: int, page: int}
     */
    public static function get_paginated_logs(int $page = 1, int $per_page = 20): array
    {
        global $wpdb;

        $table = SOS_DB::table('price_updates');
        $page = max(1, $page);
        $per_page = max(1, min(100, $per_page));
        $offset = ($page - 1) * $per_page;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $query = $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset);
        $items = $wpdb->get_results($query, ARRAY_A);

        return [
            'items'    => is_array($items) ? $items : [],
            'total'    => $total,
            'per_page' => $per_page,
            'page'     => $page,
        ];
    }

    /**
     * Approve a review item and attempt update bypassing shock threshold.
     *
     * @return array<string, mixed>
     */
    public static function approve_review_item(int $review_id): array
    {
        $review = self::get_review_item($review_id);
        if (! $review) {
            return ['success' => false, 'message' => 'Review item not found.'];
        }

        if ('pending' !== (string) $review['status']) {
            return ['success' => false, 'message' => 'Only pending review items can be approved.'];
        }

        $result = self::update_single_mapping((int) $review['map_id'], true);
        if (! empty($result['success'])) {
            self::set_review_status($review_id, 'approved');
        }

        return $result;
    }

    /**
     * Reject a review item.
     */
    public static function reject_review_item(int $review_id): bool
    {
        $review = self::get_review_item($review_id);
        if (! $review || 'pending' !== (string) $review['status']) {
            return false;
        }

        return self::set_review_status($review_id, 'rejected');
    }

    /**
     * Shared bulk update runner.
     *
     * @return array<string, mixed>
     */
    private static function run_bulk_update(string $mode): array
    {
        global $wpdb;

        $settings = (array) get_option('sos_settings', []);
        $stop_after_failures = isset($settings['stop_after_failures']) ? max(1, (int) $settings['stop_after_failures']) : 10;
        $lock = SOS_Utils::acquire_lock('updater', 1800);
        if (! $lock['acquired']) {
            return ['success' => false, 'message' => 'Updater lock is active. Another update job may still be running.'];
        }

        try {
            $table = SOS_DB::table('price_map');
            $mappings = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE sync_enabled = 1 AND status = %s ORDER BY id ASC",
                    'active'
                ),
                ARRAY_A
            );

            $total = is_array($mappings) ? count($mappings) : 0;
            $success = 0;
            $fail = 0;
            $job_id = SOS_Logger::start_job('updater', [
                'mode'                => sanitize_key($mode),
                'stop_after_failures' => $stop_after_failures,
            ]);

            if ([] === $mappings || ! is_array($mappings)) {
                SOS_Logger::finish_job($job_id, 'success', 0, 0, 0, ['message' => 'No active mappings found.']);
                return [
                    'success'       => true,
                    'message'       => 'No active mappings were available for update.',
                    'job_id'        => $job_id,
                    'total'         => 0,
                    'success_count' => 0,
                    'fail_count'    => 0,
                ];
            }

            foreach ($mappings as $row) {
                $result = self::update_single_mapping((int) $row['id']);
                if (! empty($result['success'])) {
                    ++$success;
                } else {
                    ++$fail;
                }

                if ($fail >= $stop_after_failures) {
                    SOS_Logger::finish_job($job_id, 'partial', $total, $success, $fail, [
                        'message' => 'Stopped after crossing configured failure threshold.',
                        'mode'    => sanitize_key($mode),
                    ]);
                    return [
                        'success'       => false,
                        'message'       => 'Bulk update stopped after too many failures.',
                        'job_id'        => $job_id,
                        'total'         => $total,
                        'success_count' => $success,
                        'fail_count'    => $fail,
                    ];
                }
            }

            $status = 0 === $fail ? 'success' : 'partial';
            SOS_Logger::finish_job($job_id, $status, $total, $success, $fail, [
                'message' => 'Bulk update completed.',
                'mode'    => sanitize_key($mode),
            ]);

            return [
                'success'       => true,
                'message'       => 'Bulk update completed.',
                'job_id'        => $job_id,
                'total'         => $total,
                'success_count' => $success,
                'fail_count'    => $fail,
            ];
        } finally {
            SOS_Utils::release_lock('updater');
        }
    }

    /**
     * Apply price to supported WooCommerce product types.
     */
    private static function apply_price_to_product(\WC_Product $product, string $new_price): void
    {
        if ($product->is_type('variable')) {
            self::apply_price_to_variable_product($product, $new_price);
            return;
        }

        if ($product->is_type('grouped')) {
            self::apply_price_to_grouped_product($product, $new_price);
            return;
        }

        if (method_exists($product, 'set_regular_price')) {
            $product->set_regular_price($new_price);
        }

        if (method_exists($product, 'set_price')) {
            $product->set_price($new_price);
        }

        $product->save();
    }

    /**
     * Apply one price across all variations of a variable product.
     */
    private static function apply_price_to_variable_product(\WC_Product $product, string $new_price): void
    {
        $children = method_exists($product, 'get_children') ? $product->get_children() : [];
        if (! is_array($children) || [] === $children) {
            throw new \RuntimeException('Variable product has no child variations to update.');
        }

        foreach ($children as $child_id) {
            $variation = wc_get_product((int) $child_id);
            if (! $variation) {
                continue;
            }

            if (method_exists($variation, 'set_regular_price')) {
                $variation->set_regular_price($new_price);
            }

            if (method_exists($variation, 'set_price')) {
                $variation->set_price($new_price);
            }

            $variation->save();
        }

        if (class_exists('WC_Product_Variable')) {
            \WC_Product_Variable::sync($product->get_id());
        }
    }

    /**
     * Apply one price across child products of a grouped product.
     */
    private static function apply_price_to_grouped_product(\WC_Product $product, string $new_price): void
    {
        $children = method_exists($product, 'get_children') ? $product->get_children() : [];
        if (! is_array($children) || [] === $children) {
            throw new \RuntimeException('Grouped product has no child products to update.');
        }

        foreach ($children as $child_id) {
            $child = wc_get_product((int) $child_id);
            if (! $child) {
                continue;
            }
            self::apply_price_to_product($child, $new_price);
        }
    }

    /**
     * Get review row by id.
     *
     * @return array<string, mixed>|null
     */
    private static function get_review_item(int $review_id): ?array
    {
        global $wpdb;

        $table = SOS_DB::table('price_review');
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $review_id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Update review status.
     */
    private static function set_review_status(int $review_id, string $status): bool
    {
        global $wpdb;

        $table = SOS_DB::table('price_review');
        $result = $wpdb->update(
            $table,
            [
                'status'      => sanitize_key($status),
                'reviewed_at' => SOS_Utils::mysql_now_utc(),
            ],
            ['id' => $review_id],
            ['%s', '%s'],
            ['%d']
        );

        return false !== $result;
    }

    /**
     * Get latest successful collected row for mapping.
     *
     * @return array<string, mixed>|null
     */
    private static function get_latest_successful_price_data(int $mapping_id): ?array
    {
        global $wpdb;

        $table = SOS_DB::table('price_data');
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE map_id = %d AND scrape_status = %s ORDER BY id DESC LIMIT 1",
                $mapping_id,
                'success'
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Get previous successful source price before a given record id.
     */
    private static function get_previous_successful_source_price(int $mapping_id, int $before_record_id): ?float
    {
        global $wpdb;

        $table = SOS_DB::table('price_data');
        $price = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT collected_price FROM {$table} WHERE map_id = %d AND scrape_status = %s AND id < %d ORDER BY id DESC LIMIT 1",
                $mapping_id,
                'success',
                $before_record_id
            )
        );

        return null === $price ? null : (float) $price;
    }

    /**
     * Freshness check.
     */
    private static function is_fresh_enough(string $scraped_at, int $freshness_hours): bool
    {
        $timestamp = strtotime($scraped_at . ' UTC');
        if (! $timestamp) {
            return false;
        }

        return $timestamp >= (time() - ($freshness_hours * HOUR_IN_SECONDS));
    }

    /**
     * Insert review queue item.
     */
    private static function insert_review_item(array $mapping, array $latest, float $source_price, float $computed, string $reason): void
    {
        global $wpdb;

        $table = SOS_DB::table('price_review');
        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE map_id = %d AND price_data_id = %d AND reason = %s AND status = %s LIMIT 1",
                (int) $mapping['id'],
                (int) $latest['id'],
                sanitize_key($reason),
                'pending'
            )
        );

        if ($existing_id > 0) {
            return;
        }

        $wpdb->insert(
            $table,
            [
                'map_id'               => (int) $mapping['id'],
                'woo_product_id'       => (int) $mapping['woo_product_id'],
                'price_data_id'        => (int) $latest['id'],
                'source_price'         => number_format($source_price, 2, '.', ''),
                'computed_store_price' => number_format($computed, 2, '.', ''),
                'reason'               => sanitize_key($reason),
                'status'               => 'pending',
                'created_at'           => SOS_Utils::mysql_now_utc(),
                'reviewed_at'          => null,
            ],
            ['%d', '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Log update attempt.
     */
    private static function log_update(array $mapping, ?array $latest, ?float $source_price, ?float $computed_price, ?float $old_price, string $status, string $reason, ?string $error): void
    {
        global $wpdb;

        $table = SOS_DB::table('price_updates');
        $new_price = 'updated' === $status ? $computed_price : ('dry_run' === $status ? $computed_price : null);

        $wpdb->insert(
            $table,
            [
                'job_id'               => null,
                'map_id'               => (int) $mapping['id'],
                'woo_product_id'       => (int) $mapping['woo_product_id'],
                'price_data_id'        => isset($latest['id']) ? (int) $latest['id'] : null,
                'source_price'         => null === $source_price ? null : number_format($source_price, 2, '.', ''),
                'computed_store_price' => null === $computed_price ? null : number_format($computed_price, 2, '.', ''),
                'old_woo_price'        => null === $old_price ? null : number_format($old_price, 2, '.', ''),
                'new_woo_price'        => null === $new_price ? null : number_format($new_price, 2, '.', ''),
                'update_status'        => sanitize_key($status),
                'update_reason'        => sanitize_key($reason),
                'update_error'         => $error,
                'created_at'           => SOS_Utils::mysql_now_utc(),
            ],
            ['%d', '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s']
        );
    }
}