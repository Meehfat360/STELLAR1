<?php
/**
 * Collector service used by the CLI command.
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class SOS_Collector
{
    private const LOCK_KEY = 'collector';
    private const LOCK_TIMEOUT = 1800;
    private const MAX_RAW_PAYLOAD_BYTES = 1048576;
    private const DEFAULT_CONCURRENCY = 5;
    private const MAX_CONCURRENCY = 10;

    public static function get_paginated_results(array $filters = [], int $page = 1, int $per_page = 20): array
    {
        global $wpdb;

        $table = SOS_DB::table('price_data');
        $page = max(1, $page);
        $per_page = max(1, min(100, $per_page));
        $offset = ($page - 1) * $per_page;

        $where = ['1=1'];
        $params = [];

        if (! empty($filters['scrape_status'])) {
            $where[] = 'scrape_status = %s';
            $params[] = sanitize_key((string) $filters['scrape_status']);
        }

        if (! empty($filters['woo_product_id'])) {
            $where[] = 'woo_product_id = %d';
            $params[] = absint((string) $filters['woo_product_id']);
        }

        if (! empty($filters['date_from'])) {
            $where[] = 'scraped_at >= %s';
            $params[] = sanitize_text_field((string) $filters['date_from']) . ' 00:00:00';
        }

        if (! empty($filters['date_to'])) {
            $where[] = 'scraped_at <= %s';
            $params[] = sanitize_text_field((string) $filters['date_to']) . ' 23:59:59';
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        if ([] !== $params) {
            $count_sql = $wpdb->prepare($count_sql, ...$params);
        }
        $total = (int) $wpdb->get_var($count_sql);

        $query_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, [$per_page, $offset]);
        $query_sql = $wpdb->prepare($query_sql, ...$query_params);
        $items = $wpdb->get_results($query_sql, ARRAY_A);

        return [
            'items'    => is_array($items) ? $items : [],
            'total'    => $total,
            'per_page' => $per_page,
            'page'     => $page,
        ];
    }

    public static function run(?int $forced_batch_size = null): array
    {
        if (! SOS_DB::all_tables_exist()) {
            return [
                'success' => false,
                'message' => 'Plugin tables are missing. Activate the plugin in WordPress first.',
            ];
        }

        $settings = (array) get_option('sos_settings', []);
        $batch_size = self::resolve_batch_size($forced_batch_size, $settings);

        $lock = SOS_Utils::acquire_lock(self::LOCK_KEY, self::LOCK_TIMEOUT);
        if (! $lock['acquired']) {
            return [
                'success' => false,
                'message' => 'Collector lock is already active. Another run may still be executing.',
            ];
        }

        $job_id = 0;
        $total = 0;
        $success = 0;
        $fail = 0;
        $started = microtime(true);

        try {
            $job_id = SOS_Logger::start_job('collector', [
                'batch_size' => $batch_size,
                'runtime'    => 'php-cli',
                'site_url'   => site_url(),
            ]);

            $mappings = self::get_next_batch($batch_size);
            $total = count($mappings);

            if (
                ! empty($settings['zyte_enabled']) &&
                ! empty($settings['zyte_api_key']) &&
                function_exists('curl_multi_init')
            ) {
                [$success, $fail] = self::collect_batch_via_zyte_concurrent($mappings, $job_id, $settings);
            } else {
                foreach ($mappings as $mapping) {
                    $result = self::collect_mapping($mapping, $job_id, $settings);

                    if ('success' === $result['scrape_status']) {
                        ++$success;
                    } else {
                        ++$fail;
                    }

                    self::update_mapping_timestamps((int) $mapping['id'], 'success' === $result['scrape_status']);
                    self::maybe_delay_between_requests($settings);
                }
            }

            $status = 0 === $total ? 'success' : (0 === $fail ? 'success' : ($success > 0 ? 'partial' : 'failed'));

            SOS_Logger::finish_job($job_id, $status, $total, $success, $fail, [
                'duration_seconds' => round(microtime(true) - $started, 3),
                'batch_size'       => $batch_size,
            ]);

            return [
                'success'       => true,
                'job_id'        => $job_id,
                'status'        => $status,
                'total'         => $total,
                'success_count' => $success,
                'fail_count'    => $fail,
            ];
        } catch (\Throwable $throwable) {
            if ($job_id > 0) {
                SOS_Logger::finish_job($job_id, 'failed', $total, $success, $fail, [
                    'exception' => $throwable->getMessage(),
                ]);
            }

            return [
                'success' => false,
                'message' => $throwable->getMessage(),
                'job_id'  => $job_id,
            ];
        } finally {
            SOS_Utils::release_lock(self::LOCK_KEY);
        }
    }

    private static function resolve_batch_size(?int $forced_batch_size, array $settings): int
    {
        if (null !== $forced_batch_size && $forced_batch_size > 0) {
            return max(1, min(100, $forced_batch_size));
        }

        $configured = isset($settings['batch_size']) ? (int) $settings['batch_size'] : 25;

        return max(1, min(100, $configured));
    }

    private static function get_next_batch(int $batch_size): array
    {
        global $wpdb;

        $table = SOS_DB::table('price_map');
        $query = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE sync_enabled = 1
               AND status = %s
             ORDER BY
               CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END ASC,
               last_checked_at ASC,
               id ASC
             LIMIT %d",
            'active',
            $batch_size
        );

        $rows = $wpdb->get_results($query, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    private static function collect_mapping(array $mapping, int $job_id, array $settings): array
    {
        $http = self::request_page((string) $mapping['source_url'], $settings);
        $parsed = self::parse_product_page($http['body'], (int) $http['http_status'], (string) $mapping['source_url'], $settings);

        $record = [
            'job_id'             => $job_id > 0 ? $job_id : null,
            'map_id'             => (int) $mapping['id'],
            'woo_product_id'     => (int) $mapping['woo_product_id'],
            'source_item_id'     => (string) ($mapping['source_item_id'] ?? ''),
            'source_url'         => (string) $mapping['source_url'],
            'page_title'         => $parsed['page_title'],
            'collected_price'    => $parsed['price'],
            'collected_currency' => $parsed['currency'],
            'stock_status'       => $parsed['stock_status'],
            'scrape_status'      => $parsed['scrape_status'],
            'scrape_error'       => $parsed['scrape_error'],
            'http_status'        => $http['http_status'],
            'raw_payload'        => self::truncate_payload($http['body']),
            'selector_version'   => $parsed['selector_version'],
            'scraped_at'         => SOS_Utils::mysql_now_utc(),
        ];

        self::insert_price_data($record);

        return $record;
    }

    private static function collect_batch_via_zyte_concurrent(array $mappings, int $job_id, array $settings): array
    {
        $success = 0;
        $fail = 0;
        $chunks = array_chunk($mappings, self::resolve_concurrency($settings));

        foreach ($chunks as $chunk) {
            $responses = self::request_pages_via_zyte_concurrent($chunk, $settings);

            foreach ($chunk as $mapping) {
                $mapping_id = (int) $mapping['id'];
                $http = $responses[$mapping_id] ?? [
                    'body'        => 'Zyte concurrent response missing.',
                    'http_status' => 0,
                ];

                $parsed = self::parse_product_page(
                    (string) $http['body'],
                    (int) $http['http_status'],
                    (string) $mapping['source_url'],
                    $settings
                );

                $record = [
                    'job_id'             => $job_id > 0 ? $job_id : null,
                    'map_id'             => $mapping_id,
                    'woo_product_id'     => (int) $mapping['woo_product_id'],
                    'source_item_id'     => (string) ($mapping['source_item_id'] ?? ''),
                    'source_url'         => (string) $mapping['source_url'],
                    'page_title'         => $parsed['page_title'],
                    'collected_price'    => $parsed['price'],
                    'collected_currency' => $parsed['currency'],
                    'stock_status'       => $parsed['stock_status'],
                    'scrape_status'      => $parsed['scrape_status'],
                    'scrape_error'       => $parsed['scrape_error'],
                    'http_status'        => (int) $http['http_status'],
                    'raw_payload'        => self::truncate_payload((string) $http['body']),
                    'selector_version'   => $parsed['selector_version'],
                    'scraped_at'         => SOS_Utils::mysql_now_utc(),
                ];

                self::insert_price_data($record);
                self::update_mapping_timestamps($mapping_id, 'success' === $parsed['scrape_status']);

                if ('success' === $parsed['scrape_status']) {
                    ++$success;
                } else {
                    ++$fail;
                }
            }
        }

        return [$success, $fail];
    }

    private static function resolve_concurrency(array $settings): int
    {
        $configured = isset($settings['zyte_concurrency']) ? (int) $settings['zyte_concurrency'] : self::DEFAULT_CONCURRENCY;
        return max(1, min(self::MAX_CONCURRENCY, $configured));
    }

    private static function request_pages_via_zyte_concurrent(array $mappings, array $settings): array
    {
        $api_key = (string) $settings['zyte_api_key'];
        $timeout = isset($settings['request_timeout']) ? max(10, (int) $settings['request_timeout']) : 45;
        $mode = isset($settings['zyte_mode']) ? sanitize_key((string) $settings['zyte_mode']) : 'browser_html';
        $endpoint = 'https://api.zyte.com/v1/extract';

        $multi = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($mappings as $mapping) {
            $mapping_id = (int) $mapping['id'];
            $payload = ['url' => (string) $mapping['source_url']];

            if ('http_response_body' === $mode) {
                $payload['httpResponseBody'] = true;
                $payload['httpResponseHeaders'] = true;
            } else {
                $payload['browserHtml'] = true;
            }

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Basic ' . base64_encode($api_key . ':'),
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS     => wp_json_encode($payload),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 20,
            ]);

            curl_multi_add_handle($multi, $ch);
            $handles[$mapping_id] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $mapping_id => $ch) {
            $raw = curl_multi_getcontent($ch);
            $curl_error = curl_error($ch);
            $transport_status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ('' !== $curl_error) {
                $results[$mapping_id] = [
                    'body'        => 'Zyte cURL error: ' . $curl_error,
                    'http_status' => 0,
                ];
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                continue;
            }

            $json = json_decode((string) $raw, true);

            if (! is_array($json)) {
                $results[$mapping_id] = [
                    'body'        => 'Zyte invalid JSON response: ' . (string) $raw,
                    'http_status' => $transport_status,
                ];
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                continue;
            }

            $target_status = isset($json['statusCode']) ? (int) $json['statusCode'] : $transport_status;

            if ('http_response_body' === $mode) {
                $decoded = isset($json['httpResponseBody']) ? base64_decode((string) $json['httpResponseBody'], true) : false;

                $results[$mapping_id] = [
                    'body'        => false === $decoded ? 'Zyte HTTP response body missing or invalid.' : $decoded,
                    'http_status' => $target_status,
                ];
            } else {
                $results[$mapping_id] = [
                    'body'        => isset($json['browserHtml']) ? (string) $json['browserHtml'] : 'Zyte browserHtml missing.',
                    'http_status' => $target_status,
                ];
            }

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        return $results;
    }

    private static function request_page(string $url, array $settings): array
    {
        if (! empty($settings['zyte_enabled']) && ! empty($settings['zyte_api_key'])) {
            return self::request_page_via_zyte($url, $settings);
        }

        $timeout = isset($settings['request_timeout']) ? max(5, (int) $settings['request_timeout']) : 20;
        $retries = isset($settings['request_retries']) ? max(0, min(5, (int) $settings['request_retries'])) : 2;
        $user_agent = 'StellarOverstockSync/' . SOS_VERSION . ' (+ ' . home_url('/') . ')';

        $attempt = 0;
        $last_error = null;

        while ($attempt <= $retries) {
            $response = wp_remote_get($url, [
                'timeout'     => $timeout,
                'redirection' => 5,
                'user-agent'  => $user_agent,
                'headers'     => [
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Cache-Control'   => 'no-cache',
                ],
            ]);

            if (! is_wp_error($response)) {
                return [
                    'body'        => (string) wp_remote_retrieve_body($response),
                    'http_status' => (int) wp_remote_retrieve_response_code($response),
                ];
            }

            $last_error = $response->get_error_message();
            ++$attempt;
            if ($attempt <= $retries) {
                usleep(500000);
            }
        }

        return [
            'body'        => 'WP_Error: ' . (string) $last_error,
            'http_status' => 0,
        ];
    }

    private static function request_page_via_zyte(string $url, array $settings): array
    {
        $timeout = isset($settings['request_timeout']) ? max(10, (int) $settings['request_timeout']) : 30;
        $mode = isset($settings['zyte_mode']) ? sanitize_key((string) $settings['zyte_mode']) : 'browser_html';
        $api_key = (string) $settings['zyte_api_key'];
        $endpoint = 'https://api.zyte.com/v1/extract';

        $payload = [
            'url' => $url,
        ];

        if ('http_response_body' === $mode) {
            $payload['httpResponseBody'] = true;
            $payload['httpResponseHeaders'] = true;
        } else {
            $payload['browserHtml'] = true;
        }

        $max_attempts = 4;
        $attempt = 1;
        $last_body = '';
        $last_status = 0;

        while ($attempt <= $max_attempts) {
            $response = wp_remote_post($endpoint, [
                'timeout' => $timeout,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]);

            if (is_wp_error($response)) {
                $last_body = 'Zyte WP_Error: ' . $response->get_error_message();
                $last_status = 0;

                if ($attempt < $max_attempts) {
                    sleep($attempt * 2);
                    ++$attempt;
                    continue;
                }

                return [
                    'body'        => $last_body,
                    'http_status' => $last_status,
                ];
            }

            $transport_status = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $json = json_decode($body, true);

            if (! is_array($json)) {
                $last_body = 'Zyte invalid JSON response: ' . $body;
                $last_status = $transport_status;

                if ($attempt < $max_attempts) {
                    sleep($attempt * 2);
                    ++$attempt;
                    continue;
                }

                return [
                    'body'        => $last_body,
                    'http_status' => $last_status,
                ];
            }

            $target_status = isset($json['statusCode']) ? (int) $json['statusCode'] : $transport_status;
            $last_status = $target_status;

            if (in_array($target_status, [429, 503, 520], true)) {
                $last_body = 'Zyte temporary target response: ' . $body;

                if ($attempt < $max_attempts) {
                    sleep($attempt * 3);
                    ++$attempt;
                    continue;
                }

                return [
                    'body'        => $last_body,
                    'http_status' => $last_status,
                ];
            }

            if ('http_response_body' === $mode) {
                $decoded = isset($json['httpResponseBody']) ? base64_decode((string) $json['httpResponseBody'], true) : false;

                return [
                    'body'        => false === $decoded ? 'Zyte HTTP response body missing or invalid.' : $decoded,
                    'http_status' => $target_status,
                ];
            }

            return [
                'body'        => isset($json['browserHtml']) ? (string) $json['browserHtml'] : 'Zyte browserHtml missing.',
                'http_status' => $target_status,
            ];
        }

        return [
            'body'        => $last_body,
            'http_status' => $last_status,
        ];
    }

    private static function parse_product_page(string $html, int $http_status, string $source_url, array $settings): array
    {
        $default_currency = isset($settings['default_currency']) ? sanitize_text_field((string) $settings['default_currency']) : 'USD';
        $result = [
            'price'            => null,
            'currency'         => $default_currency,
            'stock_status'     => null,
            'page_title'       => null,
            'scrape_status'    => 'failed',
            'scrape_error'     => null,
            'selector_version' => 'next_data_concurrent_v1',
        ];

        if ($http_status < 200 || $http_status >= 400) {
            $result['scrape_error'] = 'Unexpected HTTP status: ' . $http_status;
            return $result;
        }

        $result['page_title'] = self::extract_title_from_next_data($html) ?? self::extract_title($html);
        $result['currency'] = self::extract_currency_from_next_data($html) ?? self::extract_currency($html) ?? $default_currency;
        $result['stock_status'] = self::extract_stock_status_from_next_data($html) ?? self::extract_stock_status($html);

        $price = self::extract_price_from_next_data($html)
            ?? self::extract_price_from_json_ld($html)
            ?? self::extract_meta_price($html)
            ?? self::extract_price_from_price_context($html)
            ?? self::extract_inline_price($html);

        if (null === $price || $price <= 0) {
            $result['scrape_error'] = 'Visible price not found or invalid.';
            return $result;
        }

        $result['price'] = number_format($price, 2, '.', '');
        $result['scrape_status'] = 'success';
        $result['scrape_error'] = null;

        if (! SOS_Mapper::is_allowed_source_url($source_url)) {
            $result['scrape_status'] = 'failed';
            $result['scrape_error'] = 'Source URL host is not allowed.';
        }

        return $result;
    }

    private static function extract_next_data_product(string $html): ?array
    {
        if (
            ! preg_match(
                '/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is',
                $html,
                $matches
            )
        ) {
            return null;
        }

        $json = html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5);
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return null;
        }

        $product = $data['props']['pageProps']['product'] ?? null;

        return is_array($product) ? $product : null;
    }

    private static function extract_title_from_next_data(string $html): ?string
    {
        $product = self::extract_next_data_product($html);
        if (! $product) {
            return null;
        }

        $title = isset($product['name']) ? trim((string) $product['name']) : '';

        return '' !== $title ? $title : null;
    }

    private static function extract_price_from_next_data(string $html): ?float
    {
        $product = self::extract_next_data_product($html);
        if (! $product) {
            return null;
        }

        $raw =
            $product['selectedPrice']['basePrice']['amount'] ??
            $product['lowestVariationPrice']['salePrice']['amount'] ??
            $product['lowestVariationPrice']['basePrice']['amount'] ??
            $product['variations'][0]['prices']['basePrice']['amount'] ??
            null;

        if (null === $raw) {
            return null;
        }

        $num = (float) preg_replace('/[^0-9.]/', '', (string) $raw);

        return $num > 0 ? $num : null;
    }

    private static function extract_currency_from_next_data(string $html): ?string
    {
        $product = self::extract_next_data_product($html);
        if (! $product) {
            return null;
        }

        $currency =
            $product['selectedPrice']['basePrice']['currencyCode'] ??
            $product['lowestVariationPrice']['basePrice']['currencyCode'] ??
            $product['lowestVariationPrice']['salePrice']['currencyCode'] ??
            null;

        if (! is_string($currency) || '' === trim($currency)) {
            return null;
        }

        return strtoupper(trim($currency));
    }

    private static function extract_stock_status_from_next_data(string $html): ?string
    {
        $product = self::extract_next_data_product($html);
        if (! $product) {
            return null;
        }

        $value =
            $product['availability'] ??
            $product['availabilityStatus'] ??
            $product['stockStatus'] ??
            null;

        if (! is_string($value) || '' === trim($value)) {
            return null;
        }

        return strtolower(trim($value));
    }

    private static function extract_title(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = trim(wp_strip_all_tags(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5)));
            return '' !== $title ? $title : null;
        }

        return null;
    }

    private static function extract_currency(string $html): ?string
    {
        if (preg_match('/"priceCurrency"\s*:\s*"([A-Z]{3})"/i', $html, $matches)) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/property=["\']product:price:currency["\']\s+content=["\']([A-Z]{3})["\']/i', $html, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    private static function extract_stock_status(string $html): ?string
    {
        if (preg_match('/"availability"\s*:\s*"https?:\\\\\/\\\\\/schema\.org\\\\\/(InStock|OutOfStock|PreOrder)"/i', $html, $matches)) {
            return strtolower($matches[1]);
        }

        if (preg_match('/\bout of stock\b/i', $html)) {
            return 'outofstock';
        }

        if (preg_match('/\bin stock\b/i', $html)) {
            return 'instock';
        }

        return null;
    }

    private static function extract_price_from_json_ld(string $html): ?float
    {
        if (
            preg_match(
                '/"@type"\s*:\s*"Product".*?"offers"\s*:\s*{.*?"price"\s*:\s*"?([0-9]+(?:\.[0-9]{1,2})?)"?/is',
                $html,
                $matches
            )
        ) {
            return (float) $matches[1];
        }

        if (
            preg_match(
                '/"offers"\s*:\s*{.*?"price"\s*:\s*"?([0-9]+(?:\.[0-9]{1,2})?)"?/is',
                $html,
                $matches
            )
        ) {
            return (float) $matches[1];
        }

        return null;
    }

    private static function extract_meta_price(string $html): ?float
    {
        if (preg_match('/property=["\']product:price:amount["\']\s+content=["\']([0-9]+(?:\.[0-9]{1,2})?)["\']/i', $html, $matches)) {
            return (float) $matches[1];
        }

        if (preg_match('/itemprop=["\']price["\']\s+content=["\']([0-9]+(?:\.[0-9]{1,2})?)["\']/i', $html, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    private static function extract_price_from_price_context(string $html): ?float
    {
        $patterns = [
            '/class=["\'][^"\']*(price|sale-price|final-price|product-price|current-price|offer-price)[^"\']*["\'][^>]*>\s*\$?\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{2})|[0-9]+(?:\.[0-9]{2}))/is',
            '/id=["\'][^"\']*(price|sale-price|final-price|product-price|current-price|offer-price)[^"\']*["\'][^>]*>\s*\$?\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{2})|[0-9]+(?:\.[0-9]{2}))/is',
            '/data-[^=]*price[^=]*=["\']([0-9]+(?:\.[0-9]{1,2})?)["\']/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $value = end($match);
                    if (is_string($value) && is_numeric(str_replace(',', '', $value))) {
                        return (float) str_replace(',', '', $value);
                    }
                }
            }
        }

        return null;
    }

    private static function extract_inline_price(string $html): ?float
    {
        $text = wp_strip_all_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
        $text = preg_replace('/\s+/', ' ', (string) $text);

        if (
            preg_match_all(
                '/(.{0,50})\$\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{2})|[0-9]+(?:\.[0-9]{2}))(.{0,50})/i',
                (string) $text,
                $matches,
                PREG_SET_ORDER
            )
        ) {
            foreach ($matches as $match) {
                $before = strtolower((string) $match[1]);
                $amount = (float) str_replace(',', '', (string) $match[2]);
                $after  = strtolower((string) $match[3]);
                $context = $before . ' ' . $after;

                if (
                    str_contains($context, 'shipping') ||
                    str_contains($context, 'free shipping') ||
                    str_contains($context, 'delivery') ||
                    str_contains($context, 'ship ') ||
                    str_contains($context, 'ships ') ||
                    str_contains($context, 'save ') ||
                    str_contains($context, 'discount') ||
                    str_contains($context, 'coupon') ||
                    str_contains($context, 'off ') ||
                    str_contains($context, 'was $') ||
                    str_contains($context, 'starting at')
                ) {
                    continue;
                }

                return $amount;
            }
        }

        return null;
    }

    private static function insert_price_data(array $record): void
    {
        global $wpdb;

        $table = SOS_DB::table('price_data');
        $wpdb->insert(
            $table,
            $record,
            ['%d', '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    private static function update_mapping_timestamps(int $mapping_id, bool $was_success): void
    {
        global $wpdb;

        $table = SOS_DB::table('price_map');
        $data = [
            'last_checked_at' => SOS_Utils::mysql_now_utc(),
        ];
        $formats = ['%s'];

        if ($was_success) {
            $data['last_success_at'] = SOS_Utils::mysql_now_utc();
            $formats[] = '%s';
        }

        $wpdb->update($table, $data, ['id' => $mapping_id], $formats, ['%d']);
    }

    private static function maybe_delay_between_requests(array $settings): void
    {
        $delay = isset($settings['request_delay_seconds']) ? max(0, (int) $settings['request_delay_seconds']) : 1;
        if ($delay > 0) {
            sleep($delay);
        }
    }

    private static function truncate_payload(string $body): string
    {
        if (strlen($body) <= self::MAX_RAW_PAYLOAD_BYTES) {
            return $body;
        }

        return substr($body, 0, self::MAX_RAW_PAYLOAD_BYTES);
    }
}