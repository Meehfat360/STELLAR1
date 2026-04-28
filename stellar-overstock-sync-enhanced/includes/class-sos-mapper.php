<?php
/**
 * Mapping CRUD and validation.
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class SOS_Mapper
{
    /**
     * Allowed sources.
     *
     * @var array<int, string>
     */
    private const ALLOWED_SOURCES = ['overstock', 'bedbathandbeyond'];

    /**
     * Allowed profit types.
     *
     * @var array<int, string>
     */
    private const ALLOWED_PROFIT_TYPES = ['percent', 'fixed', 'percent_99'];

    /**
     * Allowed rounding types.
     *
     * @var array<int, string>
     */
    private const ALLOWED_ROUNDING_TYPES = ['none', 'nearest_99', 'nearest_95', 'nearest_whole'];

    /**
     * Allowed statuses.
     *
     * @var array<int, string>
     */
    private const ALLOWED_STATUSES = ['active', 'paused', 'review'];

    /**
     * Get paginated mappings.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, per_page: int, page: int}
     */
    public static function get_paginated(int $page = 1, int $per_page = 20): array
    {
        global $wpdb;

        $page = max(1, $page);
        $per_page = max(1, min(100, $per_page));
        $offset = ($page - 1) * $per_page;

        $table = SOS_DB::table('price_map');
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
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
     * Get a single mapping.
     *
     * @return array<string, mixed>|null
     */
    public static function get_by_id(int $id): ?array
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $table = SOS_DB::table('price_map');
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Save mapping from sanitized request payload.
     *
     * @param array<string, mixed> $raw_input Raw request input.
     * @return array{success: bool, id?: int, errors?: array<int, string>, data?: array<string, mixed>}
     */
    public static function save(array $raw_input): array
    {
        global $wpdb;

        $validation = self::validate($raw_input);
        if (! $validation['is_valid']) {
            return [
                'success' => false,
                'errors'  => $validation['errors'],
                'data'    => $validation['data'],
            ];
        }

        $data = $validation['data'];
        $table = SOS_DB::table('price_map');
        $now = SOS_Utils::mysql_now_utc();

        $record = [
            'woo_product_id'  => (int) $data['woo_product_id'],
            'source'          => (string) $data['source'],
            'source_item_id'  => $data['source_item_id'],
            'source_url'      => (string) $data['source_url'],
            'sync_enabled'    => (int) $data['sync_enabled'],
            'profit_type'     => (string) $data['profit_type'],
            'profit_value'    => (string) $data['profit_value'],
            'min_price'       => $data['min_price'],
            'max_price'       => $data['max_price'],
            'price_rounding'  => $data['price_rounding'],
            'status'          => (string) $data['status'],
            'updated_at'      => $now,
        ];

        $formats = [
            '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
        ];

        $mapping_id = (int) $data['id'];
        if ($mapping_id > 0) {
            $result = $wpdb->update(
                $table,
                $record,
                ['id' => $mapping_id],
                $formats,
                ['%d']
            );

            if (false === $result) {
                return [
                    'success' => false,
                    'errors'  => [__('Failed to update mapping record.', 'stellar-overstock-sync')],
                    'data'    => $data,
                ];
            }

            return [
                'success' => true,
                'id'      => $mapping_id,
            ];
        }

        $record['created_at'] = $now;
        $insert_formats = array_merge($formats, ['%s']);
        $result = $wpdb->insert($table, $record, $insert_formats);

        if (! $result) {
            return [
                'success' => false,
                'errors'  => [__('Failed to create mapping record.', 'stellar-overstock-sync')],
                'data'    => $data,
            ];
        }

        return [
            'success' => true,
            'id'      => (int) $wpdb->insert_id,
        ];
    }

    /**
     * Delete mapping.
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        if ($id <= 0) {
            return false;
        }

        $table = SOS_DB::table('price_map');
        $result = $wpdb->delete($table, ['id' => $id], ['%d']);

        return false !== $result;
    }

    /**
     * Prepare empty defaults for form.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'id'            => 0,
            'woo_product_id'=> '',
            'source'        => 'overstock',
            'source_item_id'=> '',
            'source_url'    => '',
            'sync_enabled'  => 1,
            'profit_type'   => 'percent',
            'profit_value'  => '20.00',
            'min_price'     => '',
            'max_price'     => '',
            'price_rounding'=> 'none',
            'status'        => 'active',
        ];
    }

    /**
     * Allowed select options.
     *
     * @return array<string, array<int, string>>
     */
    public static function option_sets(): array
    {
        return [
            'sources'       => self::ALLOWED_SOURCES,
            'profit_types'  => self::ALLOWED_PROFIT_TYPES,
            'rounding'      => self::ALLOWED_ROUNDING_TYPES,
            'statuses'      => self::ALLOWED_STATUSES,
        ];
    }

    /**
     * Validate mapping input.
     *
     * @param array<string, mixed> $raw_input Raw form payload.
     * @return array{is_valid: bool, errors: array<int, string>, data: array<string, mixed>}
     */
    public static function validate(array $raw_input): array
    {
        global $wpdb;

        $data = self::defaults();
        $errors = [];

        $data['id'] = isset($raw_input['id']) ? absint((string) $raw_input['id']) : 0;
        $data['woo_product_id'] = isset($raw_input['woo_product_id']) ? absint((string) $raw_input['woo_product_id']) : 0;
        $data['source'] = SOS_Utils::sanitize_enum((string) ($raw_input['source'] ?? 'overstock'), self::ALLOWED_SOURCES, 'overstock');
        $data['source_item_id'] = sanitize_text_field((string) ($raw_input['source_item_id'] ?? ''));
        $data['source_url'] = self::normalize_source_url((string) ($raw_input['source_url'] ?? ''));
        $data['sync_enabled'] = empty($raw_input['sync_enabled']) ? 0 : 1;
        $data['profit_type'] = SOS_Utils::sanitize_enum((string) ($raw_input['profit_type'] ?? 'percent'), self::ALLOWED_PROFIT_TYPES, 'percent');
        $data['profit_value'] = SOS_Utils::normalize_decimal_or_null($raw_input['profit_value'] ?? '0');
        $data['min_price'] = SOS_Utils::normalize_decimal_or_null($raw_input['min_price'] ?? null);
        $data['max_price'] = SOS_Utils::normalize_decimal_or_null($raw_input['max_price'] ?? null);
        $data['price_rounding'] = SOS_Utils::sanitize_enum((string) ($raw_input['price_rounding'] ?? 'none'), self::ALLOWED_ROUNDING_TYPES, 'none');
        $data['status'] = SOS_Utils::sanitize_enum((string) ($raw_input['status'] ?? 'active'), self::ALLOWED_STATUSES, 'active');

        if ((int) $data['woo_product_id'] <= 0) {
            $errors[] = __('Woo product ID is required.', 'stellar-overstock-sync');
        } else {
            $product = function_exists('wc_get_product') ? wc_get_product((int) $data['woo_product_id']) : false;
            if (! $product) {
                $errors[] = __('Woo product ID does not exist.', 'stellar-overstock-sync');
            }
        }

        if ('' === (string) $data['source_url']) {
            $errors[] = __('Source URL is required.', 'stellar-overstock-sync');
        } elseif (! self::is_allowed_source_url((string) $data['source_url'])) {
            $errors[] = __('Source URL must belong to overstock.com or bedbathandbeyond.com.', 'stellar-overstock-sync');
        }

        if (null === $data['profit_value']) {
            $errors[] = __('Profit value must be a valid number.', 'stellar-overstock-sync');
        }

        if (null !== $data['min_price'] && null !== $data['max_price'] && (float) $data['min_price'] > (float) $data['max_price']) {
            $errors[] = __('Minimum price cannot be greater than maximum price.', 'stellar-overstock-sync');
        }

        $table = SOS_DB::table('price_map');
        if ((int) $data['woo_product_id'] > 0) {
            $existing_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE woo_product_id = %d AND id != %d LIMIT 1",
                    (int) $data['woo_product_id'],
                    (int) $data['id']
                )
            );
            if ($existing_id > 0) {
                $errors[] = __('This Woo product is already mapped.', 'stellar-overstock-sync');
            }
        }

        return [
            'is_valid' => [] === $errors,
            'errors'   => $errors,
            'data'     => $data,
        ];
    }

    /**
     * Normalize and sanitize source URL.
     */
    public static function normalize_source_url(string $url): string
    {
        $url = trim($url);
        if ('' === $url) {
            return '';
        }

        $url = esc_url_raw($url, ['http', 'https']);
        if ('' === $url) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $normalized = $parts['scheme'] . '://' . strtolower((string) $parts['host']);
        $normalized .= isset($parts['path']) ? untrailingslashit((string) $parts['path']) : '';

        if (! empty($parts['query'])) {
            parse_str((string) $parts['query'], $query_args);
            ksort($query_args);
            $normalized .= '?' . http_build_query($query_args);
        }

        return $normalized;
    }

    /**
     * Ensure URL is for allowed source host.
     */
    public static function is_allowed_source_url(string $url): bool
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ('' === $host) {
            return false;
        }

        $host = strtolower($host);

        return 'overstock.com' === $host || str_ends_with($host, '.overstock.com') || 'bedbathandbeyond.com' === $host || str_ends_with($host, '.bedbathandbeyond.com') || preg_match('/(^|\.)amazon\.(com|ca)$/', $host) === 1;
    }
}
