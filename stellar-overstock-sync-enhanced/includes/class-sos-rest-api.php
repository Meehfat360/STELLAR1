<?php
/**
 * REST API endpoints for external collectors and the Chrome extension price-sync worker.
 *
 * Existing collector routes are preserved:
 *   GET  /sos/v1/collector/jobs
 *   POST /sos/v1/collector/result
 *
 * New extension routes:
 *   GET  /sos/v1/extension/status
 *   GET  /sos/v1/extension/products
 *   POST /sos/v1/extension/match
 *   POST /sos/v1/extension/price-result
 *
 * Authentication: Bearer token stored in sos_settings['collector_api_token'].
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class SOS_Rest_API
{
    private const NAMESPACE = 'sos/v1';
    private const ROUTE_JOBS = '/collector/jobs';
    private const ROUTE_RESULT = '/collector/result';
    private const ROUTE_EXTENSION_STATUS = '/extension/status';
    private const ROUTE_EXTENSION_PRODUCTS = '/extension/products';
    private const ROUTE_EXTENSION_MATCH = '/extension/match';
    private const ROUTE_EXTENSION_PRICE_RESULT = '/extension/price-result';

    /**
     * Register REST routes. Called on rest_api_init.
     */
    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE_JOBS, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_get_jobs'],
            'permission_callback' => [self::class, 'check_bearer_token'],
            'args'                => [
                'batch_size' => [
                    'type'              => 'integer',
                    'default'           => 25,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, self::ROUTE_RESULT, [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_post_result'],
            'permission_callback' => [self::class, 'check_bearer_token'],
            'args'                => self::result_args(true),
        ]);

        register_rest_route(self::NAMESPACE, self::ROUTE_EXTENSION_STATUS, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_extension_status'],
            'permission_callback' => [self::class, 'check_bearer_token'],
        ]);

        register_rest_route(self::NAMESPACE, self::ROUTE_EXTENSION_PRODUCTS, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_extension_products'],
            'permission_callback' => [self::class, 'check_bearer_token'],
            'args'                => [
                'limit' => [
                    'type'              => 'integer',
                    'default'           => 5,
                    'minimum'           => 1,
                    'maximum'           => 25,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, self::ROUTE_EXTENSION_MATCH, [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_extension_match'],
            'permission_callback' => [self::class, 'check_bearer_token'],
            'args'                => self::extension_match_args(),
        ]);

        register_rest_route(self::NAMESPACE, self::ROUTE_EXTENSION_PRICE_RESULT, [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_extension_price_result'],
            'permission_callback' => [self::class, 'check_bearer_token'],
            'args'                => self::result_args(false),
        ]);
    }

    /**
     * GET /sos/v1/collector/jobs
     */
    public static function handle_get_jobs(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        if (! SOS_DB::all_tables_exist()) {
            return new WP_REST_Response(['error' => 'Plugin tables not found.'], 503);
        }

        $settings   = (array) get_option('sos_settings', []);
        $batch_size = (int) ($request->get_param('batch_size') ?? 25);

        if (! empty($settings['batch_size'])) {
            $batch_size = min((int) $settings['batch_size'], 100);
        }

        $freshness_hours = isset($settings['freshness_window_hours'])
            ? max(1, (int) $settings['freshness_window_hours'])
            : 72;

        $map_table  = SOS_DB::table('price_map');
        $data_table = SOS_DB::table('price_data');
        $cutoff     = gmdate('Y-m-d H:i:s', time() - ($freshness_hours * HOUR_IN_SECONDS));

        $sql = $wpdb->prepare(
            "SELECT m.id AS map_id,
                    m.woo_product_id,
                    m.source_url,
                    m.source_item_id,
                    m.last_checked_at
             FROM {$map_table} m
             WHERE m.sync_enabled = 1
               AND m.status       = 'active'
               AND NOT EXISTS (
                   SELECT 1
                   FROM {$data_table} d
                   WHERE d.map_id       = m.id
                     AND d.scrape_status = 'success'
                     AND d.scraped_at   >= %s
               )
             ORDER BY m.last_checked_at ASC
             LIMIT %d",
            $cutoff,
            $batch_size
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (! is_array($rows)) {
            return new WP_REST_Response(['error' => 'Database query failed.'], 500);
        }

        $jobs = array_map(static function (array $row): array {
            return [
                'map_id'          => (int) $row['map_id'],
                'woo_product_id'  => (int) $row['woo_product_id'],
                'source_url'      => (string) $row['source_url'],
                'source_item_id'  => $row['source_item_id'] ?? null,
                'last_checked_at' => $row['last_checked_at'] ?? null,
            ];
        }, $rows);

        return new WP_REST_Response([
            'jobs'            => $jobs,
            'count'           => count($jobs),
            'batch_size'      => $batch_size,
            'freshness_hours' => $freshness_hours,
            'source'          => $source_filter,
            'generated_at'    => SOS_Utils::mysql_now_utc(),
        ], 200);
    }

    /**
     * POST /sos/v1/collector/result
     */
    public static function handle_post_result(WP_REST_Request $request): WP_REST_Response
    {
        if (! SOS_DB::all_tables_exist()) {
            return new WP_REST_Response(['error' => 'Plugin tables not found.'], 503);
        }

        $map_id = (int) $request->get_param('map_id');
        $mapping = self::get_mapping_by_id($map_id);

        if (! $mapping) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => "map_id {$map_id} not found.",
            ], 404);
        }

        $insert = self::insert_price_data_for_mapping($mapping, $request);
        if (is_wp_error($insert)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => $insert->get_error_message(),
            ], (int) ($insert->get_error_data()['status'] ?? 500));
        }

        return new WP_REST_Response([
            'success'         => true,
            'price_data_id'   => (int) $insert['price_data_id'],
            'map_id'          => $map_id,
            'woo_product_id'  => (int) $mapping['woo_product_id'],
            'scrape_status'   => (string) $insert['scrape_status'],
            'collected_price' => $insert['collected_price'],
            'stored_at'       => SOS_Utils::mysql_now_utc(),
        ], 201);
    }

    /**
     * GET /sos/v1/extension/status
     */
    public static function handle_extension_status(WP_REST_Request $request): WP_REST_Response
    {
        $settings = (array) get_option('sos_settings', []);

        return new WP_REST_Response([
            'success'             => true,
            'system'              => 'sos-extension-bridge',
            'plugin_version'      => defined('SOS_VERSION') ? SOS_VERSION : null,
            'woo_active'          => function_exists('wc_get_product'),
            'tables_ready'        => SOS_DB::all_tables_exist(),
            'dry_run_mode'        => ! empty($settings['dry_run_mode']),
            'auto_update_enabled' => ! empty($settings['auto_update_enabled']),
            'freshness_hours'     => isset($settings['freshness_window_hours']) ? (int) $settings['freshness_window_hours'] : 72,
            'server_time_utc'     => SOS_Utils::mysql_now_utc(),
        ], 200);
    }

    /**
     * GET /sos/v1/extension/products
     *
     * Returns a mixed queue:
     *  1. Existing automatic mappings that are stale and already have a source URL.
     *  2. Unmapped WooCommerce products that the extension should discover on the selected source.
     */
    public static function handle_extension_products(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        if (! SOS_DB::all_tables_exist()) {
            return new WP_REST_Response(['success' => false, 'error' => 'Plugin tables not found.'], 503);
        }

        if (! function_exists('wc_get_product')) {
            return new WP_REST_Response(['success' => false, 'error' => 'WooCommerce is not available.'], 503);
        }

        $limit = max(1, min(25, (int) ($request->get_param('limit') ?? 5)));
        $source_filter = sanitize_key((string) ($request->get_param('source') ?? 'overstock'));
        $allowed_sources = ['overstock', 'bedbathandbeyond', 'amazon', 'amazon_ca', 'auto', 'auto_ca'];
        if (! in_array($source_filter, $allowed_sources, true)) {
            $source_filter = 'overstock';
        }
        $settings = (array) get_option('sos_settings', []);
        $freshness_hours = isset($settings['freshness_window_hours']) ? max(1, (int) $settings['freshness_window_hours']) : 72;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($freshness_hours * HOUR_IN_SECONDS));
        $map_table = SOS_DB::table('price_map');
        $products = [];
        $seen = [];

        $source_sql = '';
        $source_args = [];
        if ('auto' !== $source_filter) {
            $source_sql = " AND source = %s";
            $source_args[] = $source_filter;
        }

        $mapped_sql = "SELECT id AS map_id, woo_product_id, source, source_url, source_item_id, last_checked_at
                 FROM {$map_table}
                 WHERE sync_enabled = 1
                   AND status = 'active'
                   AND source_url <> ''
                   {$source_sql}
                   AND (last_checked_at IS NULL OR last_checked_at < %s)
                 ORDER BY last_checked_at ASC, id ASC
                 LIMIT %d";
        $mapped_rows = $wpdb->get_results(
            $wpdb->prepare($mapped_sql, array_merge($source_args, [$cutoff, $limit])),
            ARRAY_A
        );

        foreach ((array) $mapped_rows as $row) {
            $item = self::hydrate_extension_product((int) $row['woo_product_id'], $row);
            if ($item) {
                $products[] = $item;
                $seen[(int) $row['woo_product_id']] = true;
            }
        }

        $remaining = $limit - count($products);
        if ($remaining > 0) {
            $post_table = $wpdb->posts;
            $postmeta_table = $wpdb->postmeta;
            $attempt_meta_key = '_sos_extension_last_attempt_at';

            $unmapped_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID
                     FROM {$post_table} p
                     LEFT JOIN {$map_table} m ON m.woo_product_id = p.ID
                     LEFT JOIN {$postmeta_table} pm
                            ON pm.post_id = p.ID
                           AND pm.meta_key = %s
                     WHERE p.post_type = 'product'
                       AND p.post_status IN ('publish', 'private', 'draft')
                       AND m.id IS NULL
                       AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value < %s)
                     ORDER BY p.ID ASC
                     LIMIT %d",
                    $attempt_meta_key,
                    $cutoff,
                    $remaining
                )
            );

            foreach ((array) $unmapped_ids as $product_id) {
                $product_id = (int) $product_id;
                if (isset($seen[$product_id])) {
                    continue;
                }
                $item = self::hydrate_extension_product($product_id, ['source_hint' => $source_filter]);
                if ($item) {
                    $products[] = $item;
                    $seen[$product_id] = true;
                }
            }
        }

        return new WP_REST_Response([
            'success'         => true,
            'products'        => $products,
            'count'           => count($products),
            'limit'           => $limit,
            'freshness_hours' => $freshness_hours,
            'source'          => $source_filter,
            'generated_at'    => SOS_Utils::mysql_now_utc(),
        ], 200);
    }

    /**
     * POST /sos/v1/extension/match
     */
    public static function handle_extension_match(WP_REST_Request $request): WP_REST_Response
    {
        if (! SOS_DB::all_tables_exist()) {
            return new WP_REST_Response(['success' => false, 'error' => 'Plugin tables not found.'], 503);
        }

        $woo_product_id = (int) $request->get_param('woo_product_id');
        $source_url = self::normalize_source_url((string) $request->get_param('source_url'));
        $source_title = sanitize_text_field((string) ($request->get_param('source_title') ?? ''));
        $confidence = max(0, min(100, (int) $request->get_param('confidence')));

        if (! self::product_exists($woo_product_id)) {
            return new WP_REST_Response(['success' => false, 'error' => 'WooCommerce product not found.'], 404);
        }

        if ('' === $source_url || ! self::is_allowed_source_url($source_url)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Source URL must be a valid Overstock, BedBathAndBeyond, or Amazon product URL.'], 400);
        }

        if ($confidence < 70) {
            self::mark_unmapped_product($woo_product_id, [
                'status'       => 'low_confidence',
                'source'       => self::source_from_url($source_url),
                'reason'       => 'Low confidence source match: ' . $confidence . '%.',
                'confidence'   => $confidence,
                'source_title' => $source_title,
                'source_url'   => $source_url,
            ]);
            return new WP_REST_Response([
                'success'    => true,
                'accepted'   => false,
                'reason'     => 'low_confidence',
                'confidence' => $confidence,
            ], 202);
        }

        $upsert = self::upsert_mapping_for_product($woo_product_id, $source_url, $source_title, $confidence);
        if (is_wp_error($upsert)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => $upsert->get_error_message(),
            ], (int) ($upsert->get_error_data()['status'] ?? 500));
        }

        return new WP_REST_Response([
            'success'        => true,
            'accepted'       => true,
            'map_id'         => (int) $upsert['map_id'],
            'woo_product_id' => $woo_product_id,
            'source_url'     => $source_url,
            'source_title'   => $source_title,
            'confidence'     => $confidence,
        ], ! empty($upsert['created']) ? 201 : 200);
    }

    /**
     * POST /sos/v1/extension/price-result
     */
    public static function handle_extension_price_result(WP_REST_Request $request): WP_REST_Response
    {
        if (! SOS_DB::all_tables_exist()) {
            return new WP_REST_Response(['success' => false, 'error' => 'Plugin tables not found.'], 503);
        }

        $woo_product_id = (int) $request->get_param('woo_product_id');
        $map_id = (int) ($request->get_param('map_id') ?? 0);
        $source_url = self::normalize_source_url((string) ($request->get_param('source_url') ?? ''));
        $source_title = sanitize_text_field((string) ($request->get_param('source_title') ?? $request->get_param('page_title') ?? ''));
        $confidence = max(0, min(100, (int) ($request->get_param('confidence') ?? 0)));
        $scrape_status = sanitize_key((string) $request->get_param('scrape_status'));

        if ($woo_product_id <= 0 && $map_id <= 0) {
            return new WP_REST_Response(['success' => false, 'error' => 'woo_product_id or map_id is required.'], 400);
        }

        $mapping = $map_id > 0 ? self::get_mapping_by_id($map_id) : null;

        if (! $mapping && $woo_product_id > 0) {
            $mapping = self::get_mapping_by_product_id($woo_product_id);
        }

        if (! $mapping && $woo_product_id > 0 && '' !== $source_url && self::is_allowed_source_url($source_url) && $confidence >= 70) {
            $upsert = self::upsert_mapping_for_product($woo_product_id, $source_url, $source_title, $confidence);
            if (is_wp_error($upsert)) {
                return new WP_REST_Response(['success' => false, 'error' => $upsert->get_error_message()], (int) ($upsert->get_error_data()['status'] ?? 500));
            }
            $mapping = self::get_mapping_by_id((int) $upsert['map_id']);
        }

        if (! $mapping) {
            if ($woo_product_id > 0) {
                self::mark_unmapped_product($woo_product_id, [
                    'status'       => $scrape_status ?: 'no_match',
                    'source'       => sanitize_key((string) ($request->get_param('source_tried') ?: $request->get_param('source') ?: '')),
                    'reason'       => sanitize_text_field((string) ($request->get_param('scrape_error') ?? 'No trusted source mapping was created.')),
                    'confidence'   => $confidence,
                    'source_title' => $source_title,
                    'source_url'   => $source_url,
                ]);
            }

            return new WP_REST_Response([
                'success'        => true,
                'stored'         => false,
                'updated'        => false,
                'blocked'        => true,
                'blocked_reason' => 'no_mapping',
                'message'        => 'Result acknowledged, but no trusted mapping exists yet.',
            ], 202);
        }

        $insert = self::insert_price_data_for_mapping($mapping, $request);
        if (is_wp_error($insert)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => $insert->get_error_message(),
            ], (int) ($insert->get_error_data()['status'] ?? 500));
        }

        $settings = (array) get_option('sos_settings', []);
        $response = [
            'success'         => true,
            'stored'          => true,
            'updated'         => false,
            'blocked'         => false,
            'price_data_id'   => (int) $insert['price_data_id'],
            'map_id'          => (int) $mapping['id'],
            'woo_product_id'  => (int) $mapping['woo_product_id'],
            'scrape_status'   => (string) $insert['scrape_status'],
            'collected_price' => $insert['collected_price'],
            'dry_run_mode'    => ! empty($settings['dry_run_mode']),
        ];

        if ('success' !== $scrape_status) {
            $response['blocked'] = true;
            $response['blocked_reason'] = 'scrape_not_successful';
            self::mark_unmapped_product((int) $mapping['woo_product_id'], [
                'status'       => $scrape_status ?: 'failed',
                'source'       => (string) ($mapping['source'] ?? sanitize_key((string) ($request->get_param('source') ?? ''))),
                'reason'       => sanitize_text_field((string) ($request->get_param('scrape_error') ?? 'Source page could not be scraped successfully.')),
                'confidence'   => $confidence,
                'source_title' => $source_title,
                'source_url'   => $source_url ?: (string) ($mapping['source_url'] ?? ''),
            ]);
            return new WP_REST_Response($response, 201);
        }

        if ((float) $insert['collected_price'] <= 0) {
            $response['blocked'] = true;
            $response['blocked_reason'] = 'invalid_price';
            self::mark_unmapped_product((int) $mapping['woo_product_id'], [
                'status'       => 'no_price',
                'source'       => (string) ($mapping['source'] ?? sanitize_key((string) ($request->get_param('source') ?? ''))),
                'reason'       => 'Source page opened, but no valid price was collected.',
                'confidence'   => $confidence,
                'source_title' => $source_title,
                'source_url'   => $source_url ?: (string) ($mapping['source_url'] ?? ''),
            ]);
            return new WP_REST_Response($response, 201);
        }

        if ($confidence > 0 && $confidence < 70) {
            $response['blocked'] = true;
            $response['blocked_reason'] = 'low_confidence';
            self::mark_unmapped_product((int) $mapping['woo_product_id'], [
                'status'       => 'low_confidence',
                'source'       => (string) ($mapping['source'] ?? sanitize_key((string) ($request->get_param('source') ?? ''))),
                'reason'       => 'Matched source confidence is below safe threshold.',
                'confidence'   => $confidence,
                'source_title' => $source_title,
                'source_url'   => $source_url ?: (string) ($mapping['source_url'] ?? ''),
            ]);
            return new WP_REST_Response($response, 201);
        }

        self::clear_unmapped_product((int) $mapping['woo_product_id']);

        if (empty($settings['auto_update_enabled']) && empty($settings['dry_run_mode'])) {
            $response['blocked'] = true;
            $response['blocked_reason'] = 'auto_update_disabled';
            $response['message'] = 'Collected price stored. Enable Auto Update or run Update Now to change WooCommerce price.';
            return new WP_REST_Response($response, 201);
        }

        $update = SOS_Updater::update_single_mapping((int) $mapping['id']);
        $response['updated'] = ! empty($update['success']) && empty($settings['dry_run_mode']);
        $response['update_result'] = [
            'success'   => ! empty($update['success']),
            'message'   => (string) ($update['message'] ?? ''),
            'old_price' => $update['old_price'] ?? null,
            'new_price' => $update['new_price'] ?? null,
        ];

        if (empty($update['success'])) {
            $response['blocked'] = true;
            $response['blocked_reason'] = 'updater_guardrail';
        }

        return new WP_REST_Response($response, 201);
    }

    /**
     * Bearer token permission check.
     */
    public static function check_bearer_token(WP_REST_Request $request): bool|WP_Error
    {
        $settings = (array) get_option('sos_settings', []);
        $stored_token = isset($settings['collector_api_token']) ? trim((string) $settings['collector_api_token']) : '';

        if ('' === $stored_token) {
            return new WP_Error(
                'sos_no_token_configured',
                'Collector / Extension API token is not configured in plugin settings.',
                ['status' => 503]
            );
        }

        $auth_header = $request->get_header('Authorization');
        $provided_token = '';
        if ($auth_header && str_starts_with($auth_header, 'Bearer ')) {
            $provided_token = trim(substr($auth_header, 7));
        }

        if ('' === $provided_token) {
            return new WP_Error(
                'sos_missing_token',
                'Authorization header missing or not Bearer type.',
                ['status' => 401]
            );
        }

        if (! hash_equals($stored_token, $provided_token)) {
            return new WP_Error('sos_invalid_token', 'Invalid API token.', ['status' => 403]);
        }

        return true;
    }

    /**
     * Insert collected price data for an existing mapping.
     *
     * @param array<string, mixed> $mapping Mapping row.
     * @return array<string, mixed>|WP_Error
     */
    private static function insert_price_data_for_mapping(array $mapping, WP_REST_Request $request): array|WP_Error
    {
        global $wpdb;

        $map_id = (int) $mapping['id'];
        $scrape_status = sanitize_key((string) $request->get_param('scrape_status'));
        $collected_price = $request->get_param('collected_price');
        $currency = $request->get_param('currency') ?? 'USD';
        $page_title = $request->get_param('page_title') ?? $request->get_param('source_title') ?? null;
        $stock_status = $request->get_param('stock_status') ?? null;
        $scrape_error = $request->get_param('scrape_error') ?? null;
        $extraction_src = $request->get_param('extraction_source') ?? null;
        $source_url = $request->get_param('source_url') ?? null;
        $scraped_at = $request->get_param('scraped_at') ?? SOS_Utils::mysql_now_utc();
        $confidence = $request->get_param('confidence');

        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $scraped_at)) {
            $scraped_at = SOS_Utils::mysql_now_utc();
        }

        $resolved_url = $source_url ? self::normalize_source_url((string) $source_url) : (string) $mapping['source_url'];
        if ('' === $resolved_url) {
            $resolved_url = (string) $mapping['source_url'];
        }

        $price_decimal = null;
        if ($collected_price !== null && $collected_price !== '') {
            $numeric = (float) preg_replace('/[^0-9.]/', '', (string) $collected_price);
            $price_decimal = $numeric > 0 ? round($numeric, 2) : null;
        }

        $allowed_stock = ['instock', 'outofstock', 'onbackorder'];
        if ($stock_status !== null) {
            $stock_status = strtolower(trim((string) $stock_status));
            if (! in_array($stock_status, $allowed_stock, true)) {
                $stock_status = match ($stock_status) {
                    'in_stock', 'in stock', 'available' => 'instock',
                    'out_of_stock', 'out of stock', 'unavailable', 'discontinued' => 'outofstock',
                    default => null,
                };
            }
        }

        $raw_payload = [
            'extension_confidence' => $confidence !== null ? (int) $confidence : null,
            'source_title'         => $page_title ? (string) $page_title : null,
            'client'               => 'chrome_extension',
        ];

        $data_table = SOS_DB::table('price_data');
        $record = [
            'job_id'             => null,
            'map_id'             => $map_id,
            'woo_product_id'     => (int) $mapping['woo_product_id'],
            'source_item_id'     => $mapping['source_item_id'] ?? null,
            'source_url'         => $resolved_url,
            'page_title'         => $page_title ? sanitize_text_field(substr((string) $page_title, 0, 500)) : null,
            'collected_price'    => $price_decimal,
            'collected_currency' => strtoupper(substr((string) $currency, 0, 3)),
            'stock_status'       => $stock_status,
            'scrape_status'      => $scrape_status,
            'scrape_error'       => $scrape_error ? substr(sanitize_textarea_field((string) $scrape_error), 0, 1000) : null,
            'http_status'        => null,
            'raw_payload'        => wp_json_encode($raw_payload),
            'selector_version'   => $extraction_src ? substr(sanitize_text_field((string) $extraction_src), 0, 40) : null,
            'scraped_at'         => $scraped_at,
        ];

        $inserted = $wpdb->insert(
            $data_table,
            $record,
            ['%s', '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        if (false === $inserted) {
            return new WP_Error('sos_price_data_insert_failed', 'DB insert failed: ' . $wpdb->last_error, ['status' => 500]);
        }

        $now = SOS_Utils::mysql_now_utc();
        $map_update = ['last_checked_at' => $now, 'updated_at' => $now];
        $formats = ['%s', '%s'];
        if ('success' === $scrape_status) {
            $map_update['last_success_at'] = $now;
            $formats[] = '%s';
        }

        $wpdb->update(SOS_DB::table('price_map'), $map_update, ['id' => $map_id], $formats, ['%d']);
        update_post_meta((int) $mapping['woo_product_id'], '_sos_extension_last_attempt_at', $now);
        if ('success' === $scrape_status) {
            delete_post_meta((int) $mapping['woo_product_id'], '_sos_extension_last_error');
        } elseif ($scrape_error) {
            update_post_meta((int) $mapping['woo_product_id'], '_sos_extension_last_error', sanitize_text_field((string) $scrape_error));
        }

        return [
            'price_data_id'  => (int) $wpdb->insert_id,
            'scrape_status'  => $scrape_status,
            'collected_price'=> $price_decimal,
        ];
    }

    /**
     * Create or update an automatic mapping for a product.
     *
     * @return array<string, mixed>|WP_Error
     */
    private static function upsert_mapping_for_product(int $woo_product_id, string $source_url, string $source_title, int $confidence): array|WP_Error
    {
        global $wpdb;

        if (! self::product_exists($woo_product_id)) {
            return new WP_Error('sos_product_not_found', 'WooCommerce product not found.', ['status' => 404]);
        }

        if ('' === $source_url || ! self::is_allowed_source_url($source_url)) {
            return new WP_Error('sos_invalid_source_url', 'Invalid source URL.', ['status' => 400]);
        }

        $table = SOS_DB::table('price_map');
        $now = SOS_Utils::mysql_now_utc();
        $existing = self::get_mapping_by_product_id($woo_product_id);
        $source_item_id = self::source_item_id_from_url($source_url);

        if ($existing) {
            $updated = $wpdb->update(
                $table,
                [
                    'source'         => self::source_from_url($source_url),
                    'source_item_id' => $source_item_id,
                    'source_url'     => $source_url,
                    'sync_enabled'   => 1,
                    'status'         => 'active',
                    'updated_at'     => $now,
                ],
                ['id' => (int) $existing['id']],
                ['%s', '%s', '%s', '%d', '%s', '%s'],
                ['%d']
            );

            if (false === $updated) {
                return new WP_Error('sos_mapping_update_failed', 'Failed to update mapping: ' . $wpdb->last_error, ['status' => 500]);
            }

            $map_id = (int) $existing['id'];
            $created = false;
        } else {
            $inserted = $wpdb->insert(
                $table,
                [
                    'woo_product_id' => $woo_product_id,
                    'source'         => self::source_from_url($source_url),
                    'source_item_id' => $source_item_id,
                    'source_url'     => $source_url,
                    'sync_enabled'   => 1,
                    'profit_type'    => 'percent',
                    'profit_value'   => '0.00',
                    'min_price'      => null,
                    'max_price'      => null,
                    'price_rounding' => 'none',
                    'status'         => 'active',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ],
                ['%d', '%s', '%s', '%s', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s']
            );

            if (false === $inserted) {
                return new WP_Error('sos_mapping_insert_failed', 'Failed to create mapping: ' . $wpdb->last_error, ['status' => 500]);
            }

            $map_id = (int) $wpdb->insert_id;
            $created = true;
        }

        update_post_meta($woo_product_id, '_sos_extension_source_title', $source_title);
        update_post_meta($woo_product_id, '_sos_extension_match_confidence', $confidence);
        update_post_meta($woo_product_id, '_sos_extension_matched_at', $now);
        update_post_meta($woo_product_id, '_sos_extension_last_attempt_at', $now);
        delete_post_meta($woo_product_id, '_sos_extension_last_error');
        self::clear_unmapped_product($woo_product_id);

        return [
            'map_id'  => $map_id,
            'created' => $created,
        ];
    }

    /**
     * Hydrate one product row for extension consumption.
     *
     * @param array<string, mixed>|null $mapping Optional mapping row.
     * @return array<string, mixed>|null
     */
    private static function hydrate_extension_product(int $product_id, ?array $mapping): ?array
    {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : false;
        if (! $product) {
            return null;
        }

        $title = $product->get_name();
        if ('' === trim((string) $title)) {
            return null;
        }

        return [
            'woo_product_id'  => $product_id,
            'map_id'          => $mapping['map_id'] ?? $mapping['id'] ?? null,
            'title'           => html_entity_decode(wp_strip_all_tags((string) $title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'sku'             => (string) $product->get_sku(),
            'current_price'   => (string) $product->get_price(),
            'regular_price'   => (string) $product->get_regular_price(),
            'sale_price'      => (string) $product->get_sale_price(),
            'product_type'    => (string) $product->get_type(),
            'source'          => isset($mapping['source']) ? (string) $mapping['source'] : (string) ($mapping['source_hint'] ?? 'overstock'),
            'source_hint'     => (string) ($mapping['source_hint'] ?? ''),
            'source_url'      => isset($mapping['source_url']) ? (string) $mapping['source_url'] : '',
            'source_item_id'  => $mapping['source_item_id'] ?? '',
            'source_title'    => (string) get_post_meta($product_id, '_sos_extension_source_title', true),
            'last_checked_at' => $mapping['last_checked_at'] ?? null,
            'last_attempt_at' => (string) get_post_meta($product_id, '_sos_extension_last_attempt_at', true),
        ];
    }

    /**
     * Get mapping by ID.
     *
     * @return array<string, mixed>|null
     */
    private static function get_mapping_by_id(int $map_id): ?array
    {
        global $wpdb;

        if ($map_id <= 0) {
            return null;
        }

        $table = SOS_DB::table('price_map');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $map_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Get mapping by Woo product ID.
     *
     * @return array<string, mixed>|null
     */
    private static function get_mapping_by_product_id(int $product_id): ?array
    {
        global $wpdb;

        if ($product_id <= 0) {
            return null;
        }

        $table = SOS_DB::table('price_map');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE woo_product_id = %d LIMIT 1", $product_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function product_exists(int $product_id): bool
    {
        return $product_id > 0 && function_exists('wc_get_product') && (bool) wc_get_product($product_id);
    }

    private static function normalize_source_url(string $url): string
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

        return $normalized;
    }

    private static function is_allowed_source_url(string $url): bool
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ('' === $host) {
            return false;
        }

        $host = strtolower($host);
        $is_overstock = 'overstock.com' === $host || str_ends_with($host, '.overstock.com');
        $is_bedbath = 'bedbathandbeyond.com' === $host || str_ends_with($host, '.bedbathandbeyond.com');
        $is_amazon = preg_match('/(^|\.)amazon\.(com|ca)$/', $host) === 1;

        if (! $is_overstock && ! $is_bedbath && ! $is_amazon) {
            return false;
        }

        $path_l = strtolower($path);
        if ($is_amazon) {
            return (bool) preg_match('/\/(dp|gp\/product)\/[A-Z0-9]{10}/i', $path);
        }

        return str_contains($path_l, '/product') || str_ends_with($path_l, '.html');
    }

    private static function source_from_url(string $url): string
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (str_contains($host, 'amazon.ca')) {
            return 'amazon_ca';
        }
        if (preg_match('/(^|\.)amazon\.com$/', $host)) {
            return 'amazon';
        }
        return str_contains($host, 'bedbathandbeyond') ? 'bedbathandbeyond' : 'overstock';
    }

    private static function source_item_id_from_url(string $url): ?string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        if (preg_match('/\/(?:dp|gp\/product)\/([A-Z0-9]{10})/i', $path, $matches)) {
            return strtoupper(sanitize_text_field($matches[1]));
        }
        if ($query) {
            parse_str($query, $args);
            foreach (['asin', 'ASIN'] as $key) {
                if (! empty($args[$key]) && preg_match('/^[A-Z0-9]{10}$/i', (string) $args[$key])) {
                    return strtoupper(sanitize_text_field((string) $args[$key]));
                }
            }
        }
        if (preg_match('/\/(\d+)\/product\.html$/i', $path, $matches)) {
            return sanitize_text_field($matches[1]);
        }
        if (preg_match('/([0-9]{5,})/', $path, $matches)) {
            return sanitize_text_field($matches[1]);
        }
        return null;
    }

    /**
     * Mark a product as needing manual source review in the Unmapped Catalog.
     *
     * @param array<string, mixed> $details
     */
    private static function mark_unmapped_product(int $woo_product_id, array $details): void
    {
        if ($woo_product_id <= 0) {
            return;
        }

        $now = SOS_Utils::mysql_now_utc();
        $status = sanitize_key((string) ($details['status'] ?? 'failed'));
        $source = sanitize_key((string) ($details['source'] ?? ''));
        if (str_contains($source, ',')) {
            $source = str_contains($source, 'amazon_ca') ? 'overstock_amazon_ca' : (str_contains($source, 'amazon') ? 'overstock_amazon' : $source);
        } elseif ('overstockamazon' === $source || str_contains($source, 'overstock_amazon')) {
            $source = str_contains($source, 'amazon_ca') ? 'overstock_amazon_ca' : 'overstock_amazon';
        }
        $reason = sanitize_text_field((string) ($details['reason'] ?? 'No confident source match was found.'));
        $confidence = max(0, min(100, (int) ($details['confidence'] ?? 0)));
        $source_title = sanitize_text_field((string) ($details['source_title'] ?? ''));
        $source_url = self::normalize_source_url((string) ($details['source_url'] ?? ''));

        if ('' === $status) {
            $status = 'failed';
        }

        update_post_meta($woo_product_id, '_sos_extension_unmapped_status', $status);
        update_post_meta($woo_product_id, '_sos_extension_unmapped_source', $source);
        update_post_meta($woo_product_id, '_sos_extension_unmapped_reason', $reason);
        update_post_meta($woo_product_id, '_sos_extension_unmapped_confidence', $confidence);
        update_post_meta($woo_product_id, '_sos_extension_unmapped_source_title', $source_title);
        update_post_meta($woo_product_id, '_sos_extension_unmapped_source_url', $source_url);
        update_post_meta($woo_product_id, '_sos_extension_unmapped_at', $now);
        update_post_meta($woo_product_id, '_sos_extension_last_attempt_at', $now);
        update_post_meta($woo_product_id, '_sos_extension_last_error', $reason);

        $fail_count = (int) get_post_meta($woo_product_id, '_sos_extension_no_match_count', true);
        update_post_meta($woo_product_id, '_sos_extension_no_match_count', $fail_count + 1);
    }

    /**
     * Clear a product from the Unmapped Catalog after a successful match/price collection.
     */
    private static function clear_unmapped_product(int $woo_product_id): void
    {
        if ($woo_product_id <= 0) {
            return;
        }

        foreach ([
            '_sos_extension_unmapped_status',
            '_sos_extension_unmapped_source',
            '_sos_extension_unmapped_reason',
            '_sos_extension_unmapped_confidence',
            '_sos_extension_unmapped_source_title',
            '_sos_extension_unmapped_source_url',
            '_sos_extension_unmapped_at',
            '_sos_extension_last_error',
        ] as $meta_key) {
            delete_post_meta($woo_product_id, $meta_key);
        }
    }

    /**
     * REST arg definitions for collector/extension result endpoints.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function result_args(bool $require_map_id): array
    {
        return [
            'map_id' => [
                'type'              => 'integer',
                'required'          => $require_map_id,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'woo_product_id' => [
                'type'              => 'integer',
                'required'          => false,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'scrape_status' => [
                'type'              => 'string',
                'required'          => true,
                'enum'              => ['success', 'failed', 'timeout', 'blocked', 'no_price'],
                'sanitize_callback' => 'sanitize_key',
            ],
            'collected_price' => [
                'type'     => 'string',
                'required' => false,
                'default'  => null,
            ],
            'currency' => [
                'type'              => 'string',
                'required'          => false,
                'default'           => 'USD',
                'sanitize_callback' => static fn($v) => strtoupper(sanitize_text_field((string) $v)),
            ],
            'page_title' => [
                'type'              => 'string',
                'required'          => false,
                'default'           => null,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'source_title' => [
                'type'              => 'string',
                'required'          => false,
                'default'           => null,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'source_url' => [
                'type'              => 'string',
                'required'          => false,
                'default'           => null,
                'sanitize_callback' => 'esc_url_raw',
            ],
            'source' => [
                'type'              => 'string',
                'required'          => false,
                'default'           => null,
                'sanitize_callback' => 'sanitize_key',
            ],
            'source_tried' => [
                'type'              => 'string',
                'required'          => false,
                'default'           => null,
                'sanitize_callback' => static fn($v) => sanitize_text_field((string) $v),
            ],
            'stock_status' => [
                'type'     => 'string',
                'required' => false,
                'default'  => null,
            ],
            'scrape_error' => [
                'type'     => 'string',
                'required' => false,
                'default'  => null,
            ],
            'extraction_source' => [
                'type'              => 'string',
                'required'          => false,
                'default'           => null,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'confidence' => [
                'type'              => 'integer',
                'required'          => false,
                'default'           => 0,
                'minimum'           => 0,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
            ],
            'scraped_at' => [
                'type'     => 'string',
                'required' => false,
                'default'  => null,
            ],
        ];
    }

    /**
     * REST arg definitions for POST /extension/match.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function extension_match_args(): array
    {
        return [
            'woo_product_id' => [
                'type'              => 'integer',
                'required'          => true,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'source_url' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'esc_url_raw',
            ],
            'source_title' => [
                'type'              => 'string',
                'required'          => false,
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'confidence' => [
                'type'              => 'integer',
                'required'          => true,
                'minimum'           => 0,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
            ],
        ];
    }
}
