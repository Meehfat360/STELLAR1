<?php
/**
 * Admin menu and page skeletons.
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class SOS_Admin
{
    /**
     * Singleton instance.
     *
     * @var SOS_Admin|null
     */
    private static ?SOS_Admin $instance = null;

    /**
     * Admin menu slug.
     */
    private const MENU_SLUG = 'sos-dashboard';

    /**
     * Save action slug.
     */
    private const SAVE_ACTION = 'sos_save_mapping';

    /**
     * Delete action slug.
     */
    private const DELETE_ACTION = 'sos_delete_mapping';

    /**
     * Manual single update action slug.
     */
    private const UPDATE_SINGLE_ACTION = 'sos_update_single_mapping';

    /**
     * Manual bulk update action slug.
     */
    private const UPDATE_BULK_ACTION = 'sos_update_bulk_mappings';

    /**
     * Review queue approve action slug.
     */
    private const REVIEW_APPROVE_ACTION = 'sos_review_approve';

    /**
     * Review queue reject action slug.
     */
    private const REVIEW_REJECT_ACTION = 'sos_review_reject';

    /**
     * Settings save action slug.
     */
    private const SAVE_SETTINGS_ACTION = 'sos_save_settings';

    /**
     * Clear unmapped catalog item action slug.
     */
    private const CLEAR_UNMAPPED_ACTION = 'sos_clear_unmapped_product';

    /**
     * Get singleton instance.
     */
    public static function instance(): SOS_Admin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register admin hooks.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'maybe_handle_activation_redirect']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save_mapping']);
        add_action('admin_post_' . self::DELETE_ACTION, [$this, 'handle_delete_mapping']);
        add_action('admin_post_' . self::UPDATE_SINGLE_ACTION, [$this, 'handle_update_single_mapping']);
        add_action('admin_post_' . self::UPDATE_BULK_ACTION, [$this, 'handle_update_bulk_mappings']);
        add_action('admin_post_' . self::REVIEW_APPROVE_ACTION, [$this, 'handle_review_approve']);
        add_action('admin_post_' . self::REVIEW_REJECT_ACTION, [$this, 'handle_review_reject']);
        add_action('admin_post_' . self::SAVE_SETTINGS_ACTION, [$this, 'handle_save_settings']);
        add_action('admin_post_' . self::CLEAR_UNMAPPED_ACTION, [$this, 'handle_clear_unmapped_product']);
        add_action('wp_ajax_sos_update_profit_rule', [$this, 'ajax_update_profit_rule']);
    }

    /**
     * Disallow direct construction.
     */
    private function __construct()
    {
    }

    /**
     * Register menu pages.
     */
    public function register_admin_menu(): void
    {
        if (! SOS_Utils::current_user_can_manage_plugin()) {
            return;
        }

        $capability = $this->get_capability();

        add_menu_page(
            __('Stellar Sync', 'stellar-overstock-sync'),
            __('Stellar Sync', 'stellar-overstock-sync'),
            $capability,
            self::MENU_SLUG,
            [$this, 'render_dashboard_page'],
            'dashicons-update',
            56
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard', 'stellar-overstock-sync'),
            __('Dashboard', 'stellar-overstock-sync'),
            $capability,
            self::MENU_SLUG,
            [$this, 'render_dashboard_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Auto Links', 'stellar-overstock-sync'),
            __('Auto Links', 'stellar-overstock-sync'),
            $capability,
            'sos-mappings',
            [$this, 'render_mappings_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Unmapped Catalog', 'stellar-overstock-sync'),
            __('Unmapped Catalog', 'stellar-overstock-sync'),
            $capability,
            'sos-unmapped-catalog',
            [$this, 'render_unmapped_catalog_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Collected Prices', 'stellar-overstock-sync'),
            __('Collected Prices', 'stellar-overstock-sync'),
            $capability,
            'sos-collected-prices',
            [$this, 'render_collected_prices_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Review Queue', 'stellar-overstock-sync'),
            __('Review Queue', 'stellar-overstock-sync'),
            $capability,
            'sos-review-queue',
            [$this, 'render_review_queue_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Update Logs', 'stellar-overstock-sync'),
            __('Update Logs', 'stellar-overstock-sync'),
            $capability,
            'sos-update-logs',
            [$this, 'render_update_logs_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'stellar-overstock-sync'),
            __('Settings', 'stellar-overstock-sync'),
            $capability,
            'sos-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Enqueue minimal admin assets on plugin pages only.
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        if (! $this->is_plugin_screen()) {
            return;
        }

        wp_enqueue_style(
            'sos-admin',
            SOS_PLUGIN_URL . 'assets/admin.css',
            [],
            SOS_VERSION
        );

        wp_enqueue_script(
            'sos-admin',
            SOS_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            SOS_VERSION,
            true
        );

        wp_localize_script(
            'sos-admin',
            'sosAdmin',
            [
                'hookSuffix'   => $hook_suffix,
                'version'      => SOS_VERSION,
                'ajaxUrl'      => admin_url('admin-ajax.php'),
                'profitNonce'  => wp_create_nonce('sos_update_profit_rule'),
            ]
        );
    }

    /**
     * Redirect to dashboard once after activation for authorized users.
     */
    public function maybe_handle_activation_redirect(): void
    {
        if (! is_admin() || wp_doing_ajax()) {
            return;
        }

        if (! SOS_Utils::current_user_can_manage_plugin()) {
            return;
        }

        if ('1' !== get_option('sos_do_activation_redirect')) {
            return;
        }

        delete_option('sos_do_activation_redirect');

        if (isset($_GET['activate-multi'])) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    /**
     * Handle mapping save action.
     */
    public function handle_save_mapping(): void
    {
        if (! SOS_Utils::current_user_can_manage_plugin()) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'stellar-overstock-sync'), '', ['response' => 403]);
        }

        check_admin_referer('sos_save_mapping');

        $result = SOS_Mapper::save($_POST);
        if (! $result['success']) {
            $payload = [
                'errors' => $result['errors'] ?? [],
                'form'   => $result['data'] ?? [],
            ];
            set_transient('sos_mapping_form_state_' . get_current_user_id(), $payload, MINUTE_IN_SECONDS * 10);

            wp_safe_redirect(add_query_arg([
                'page'    => 'sos-mappings',
                'action'  => 'edit',
                'mapping' => isset($_POST['id']) ? absint((string) $_POST['id']) : 0,
                'notice'  => 'error',
            ], admin_url('admin.php')));
            exit;
        }

        delete_transient('sos_mapping_form_state_' . get_current_user_id());

        wp_safe_redirect(add_query_arg([
            'page'    => 'sos-mappings',
            'action'  => 'edit',
            'mapping' => (int) $result['id'],
            'notice'  => 'saved',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handle mapping deletion.
     */
    public function handle_delete_mapping(): void
    {
        if (! SOS_Utils::current_user_can_manage_plugin()) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'stellar-overstock-sync'), '', ['response' => 403]);
        }

        check_admin_referer('sos_delete_mapping');

        $mapping_id = isset($_POST['mapping_id']) ? absint((string) $_POST['mapping_id']) : 0;
        $deleted = SOS_Mapper::delete($mapping_id);

        wp_safe_redirect(add_query_arg([
            'page'   => 'sos-mappings',
            'notice' => $deleted ? 'deleted' : 'delete_error',
        ], admin_url('admin.php')));
        exit;
    }





    /**
     * Handle settings save.
     */
    public function handle_save_settings(): void
    {
        if (! SOS_Utils::current_user_can_manage_plugin()) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'stellar-overstock-sync'), '', ['response' => 403]);
        }

        check_admin_referer('sos_save_settings');

        $settings = (array) get_option('sos_settings', []);
        $settings['freshness_window_hours'] = isset($_POST['freshness_window_hours']) ? max(1, (int) $_POST['freshness_window_hours']) : 72;
        $settings['batch_size'] = isset($_POST['batch_size']) ? max(1, min(100, (int) $_POST['batch_size'])) : 25;
        $settings['request_timeout'] = isset($_POST['request_timeout']) ? max(5, min(120, (int) $_POST['request_timeout'])) : 20;
        $settings['request_retries'] = isset($_POST['request_retries']) ? max(0, min(5, (int) $_POST['request_retries'])) : 2;
        $settings['request_delay_seconds'] = isset($_POST['request_delay_seconds']) ? max(0, min(10, (int) $_POST['request_delay_seconds'])) : 1;
        $settings['default_currency'] = isset($_POST['default_currency']) ? strtoupper(substr(sanitize_text_field((string) $_POST['default_currency']), 0, 3)) : 'USD';
        $settings['shock_threshold_pct'] = isset($_POST['shock_threshold_pct']) ? max(1, min(500, (int) $_POST['shock_threshold_pct'])) : 25;
        $settings['stop_after_failures'] = isset($_POST['stop_after_failures']) ? max(1, min(500, (int) $_POST['stop_after_failures'])) : 10;
        $settings['dry_run_mode'] = empty($_POST['dry_run_mode']) ? 0 : 1;
        $settings['auto_update_enabled'] = empty($_POST['auto_update_enabled']) ? 0 : 1;
        $settings['zyte_enabled'] = empty($_POST['zyte_enabled']) ? 0 : 1;
        $settings['zyte_api_key'] = isset($_POST['zyte_api_key']) ? sanitize_text_field((string) $_POST['zyte_api_key']) : '';
        $settings['zyte_mode'] = isset($_POST['zyte_mode']) ? sanitize_key((string) $_POST['zyte_mode']) : 'browser_html';
        if (! in_array($settings['zyte_mode'], ['browser_html', 'http_response_body'], true)) {
            $settings['zyte_mode'] = 'browser_html';
        }

        if (empty($settings['collector_api_token'])) {
            $settings['collector_api_token'] = bin2hex(random_bytes(32));
        }

        update_option('sos_settings', $settings, false);

        wp_safe_redirect(add_query_arg([
            'page' => 'sos-settings',
            'notice' => 'saved',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handle review approval.
     */
    public function handle_review_approve(): void
    {
        if (! SOS_Utils::current_user_can_manage_plugin()) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'stellar-overstock-sync'), '', ['response' => 403]);
        }

        check_admin_referer('sos_review_approve');
        $review_id = isset($_POST['review_id']) ? absint((string) $_POST['review_id']) : 0;
        $result = SOS_Updater::approve_review_item($review_id);

        wp_safe_redirect(add_query_arg([
            'page' => 'sos-review-queue',
            'notice' => ! empty($result['success']) ? 'review_approved' : 'review_error',
            'message' => rawurlencode((string) ($result['message'] ?? '')),
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handle review rejection.
     */
    public function handle_review_reject(): void
    {
        if (! SOS_Utils::current_user_can_manage_plugin()) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'stellar-overstock-sync'), '', ['response' => 403]);
        }

        check_admin_referer('sos_review_reject');
        $review_id = isset($_POST['review_id']) ? absint((string) $_POST['review_id']) : 0;
        $result = SOS_Updater::reject_review_item($review_id);

        wp_safe_redirect(add_query_arg([
            'page' => 'sos-review-queue',
            'notice' => $result ? 'review_rejected' : 'review_error',
            'message' => rawurlencode((string) ($result ? __('Review item rejected.', 'stellar-overstock-sync') : __('Unable to reject review item.', 'stellar-overstock-sync'))),
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handle bulk mapping update action.
     */
    public function handle_update_bulk_mappings(): void
    {
        if (! SOS_Utils::current_user_can_manage_plugin()) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'stellar-overstock-sync'), '', ['response' => 403]);
        }

        check_admin_referer('sos_update_bulk_mappings');

        $result = SOS_Updater::bulk_update_safe_mappings();

        wp_safe_redirect(add_query_arg([
            'page' => 'sos-mappings',
            'notice' => ! empty($result['success']) ? 'bulk_update_success' : 'bulk_update_error',
            'message' => rawurlencode((string) ($result['message'] ?? '')),
            'job_id' => (int) ($result['job_id'] ?? 0),
            'processed_total' => (int) ($result['total'] ?? 0),
            'processed_success' => (int) ($result['success_count'] ?? 0),
            'processed_fail' => (int) ($result['fail_count'] ?? 0),
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handle single mapping update action.
     */
    public function handle_update_single_mapping(): void
    {
        if (! SOS_Utils::current_user_can_manage_plugin()) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'stellar-overstock-sync'), '', ['response' => 403]);
        }

        check_admin_referer('sos_update_single_mapping');

        $mapping_id = isset($_POST['mapping_id']) ? absint((string) $_POST['mapping_id']) : 0;
        $result = SOS_Updater::update_single_mapping($mapping_id);

        wp_safe_redirect(add_query_arg([
            'page' => 'sos-mappings',
            'mapping' => $mapping_id,
            'notice' => ! empty($result['success']) ? 'update_success' : 'update_error',
            'message' => rawurlencode((string) ($result['message'] ?? '')),
            'updated_mapping' => $mapping_id,
            'update_status' => sanitize_key((string) ($result['update_status'] ?? '')),
            'new_price' => rawurlencode((string) ($result['new_price'] ?? '')),
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handle clearing a product from the Unmapped Catalog.
     */
    public function handle_clear_unmapped_product(): void
    {
        if (! SOS_Utils::current_user_can_manage_plugin()) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'stellar-overstock-sync'), '', ['response' => 403]);
        }

        check_admin_referer('sos_clear_unmapped_product');

        $product_id = isset($_POST['product_id']) ? absint((string) $_POST['product_id']) : 0;
        $cleared    = false;

        if ($product_id > 0) {
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
                delete_post_meta($product_id, $meta_key);
            }
            $cleared = true;
        }

        wp_safe_redirect(add_query_arg([
            'page'   => 'sos-unmapped-catalog',
            'notice' => $cleared ? 'cleared' : 'clear_error',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * AJAX: update profit rule (type + value) for a mapping.
     */
    public function ajax_update_profit_rule(): void
    {
        if (! SOS_Utils::current_user_can_manage_plugin()) {
            wp_send_json_error(__('Permission denied.', 'stellar-overstock-sync'), 403);
        }

        check_ajax_referer('sos_update_profit_rule');

        $mapping_id   = isset($_POST['mapping_id']) ? absint((string) $_POST['mapping_id']) : 0;
        $profit_type  = isset($_POST['profit_type']) ? sanitize_key((string) $_POST['profit_type']) : 'percent';
        $profit_value = isset($_POST['profit_value']) ? sanitize_text_field((string) $_POST['profit_value']) : '';

        if ($mapping_id <= 0) {
            wp_send_json_error(__('Invalid mapping ID.', 'stellar-overstock-sync'));
        }

        $allowed_types = ['percent', 'fixed', 'percent_99'];
        if (! in_array($profit_type, $allowed_types, true)) {
            $profit_type = 'percent';
        }

        /* Only allow the four preset values (15, 20, 25, 30) */
        $allowed_values = ['15', '20', '25', '30'];
        if (! in_array($profit_value, $allowed_values, true)) {
            wp_send_json_error(__('Invalid profit value. Allowed: 15, 20, 25, 30.', 'stellar-overstock-sync'));
        }

        global $wpdb;
        $table = SOS_DB::table('price_map');

        $result = $wpdb->update(
            $table,
            [
                'profit_type'  => $profit_type,
                'profit_value' => number_format((float) $profit_value, 2, '.', ''),
                'updated_at'   => SOS_Utils::mysql_now_utc(),
            ],
            ['id' => $mapping_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        // $wpdb->update() returns false on DB error, 0 when the row exists but the
        // value is unchanged (not an error), or a positive integer on actual update.
        if (false === $result) {
            wp_send_json_error(__('Database update failed.', 'stellar-overstock-sync'));
        }

        wp_send_json_success([
            'mapping_id'   => $mapping_id,
            'profit_type'  => $profit_type,
            'profit_value' => $profit_value,
        ]);
    }

    /**
     * Render dashboard page.
     */
    public function render_dashboard_page(): void
    {
        $stats = $this->get_stage_one_stats();
        require SOS_PLUGIN_DIR . 'admin/views-dashboard.php';
    }

    /**
     * Render mappings page.
     */
    public function render_mappings_page(): void
    {
        $current_page = isset($_GET['paged']) ? max(1, absint((string) $_GET['paged'])) : 1;
        $edit_id = isset($_GET['mapping']) ? absint((string) $_GET['mapping']) : 0;
        $option_sets = SOS_Mapper::option_sets();
        $listing = SOS_Mapper::get_paginated($current_page, 20);
        $mapping_ids = array_map(static function ($row): int {
            return isset($row['id']) ? (int) $row['id'] : 0;
        }, $listing['items']);
        $latest_update_statuses = SOS_Updater::get_latest_update_statuses($mapping_ids);
        $form_state = get_transient('sos_mapping_form_state_' . get_current_user_id());

        if (is_array($form_state) && isset($form_state['form']) && is_array($form_state['form'])) {
            $form_values = array_merge(SOS_Mapper::defaults(), $form_state['form']);
            $form_errors = isset($form_state['errors']) && is_array($form_state['errors']) ? $form_state['errors'] : [];
        } elseif ($edit_id > 0) {
            $form_values = SOS_Mapper::get_by_id($edit_id) ?? SOS_Mapper::defaults();
            $form_errors = [];
        } else {
            $form_values = SOS_Mapper::defaults();
            $form_errors = [];
        }

        $notice = isset($_GET['notice']) ? sanitize_key((string) $_GET['notice']) : '';
        require SOS_PLUGIN_DIR . 'admin/views-mapping.php';
    }

    /**
     * Render Unmapped Catalog page.
     */
    public function render_unmapped_catalog_page(): void
    {
        $current_page = isset($_GET['paged']) ? max(1, absint((string) $_GET['paged'])) : 1;
        $filters = [
            'source' => isset($_GET['source']) ? sanitize_key((string) $_GET['source']) : '',
            'status' => isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '',
            's' => isset($_GET['s']) ? sanitize_text_field((string) $_GET['s']) : '',
        ];
        $listing = $this->get_unmapped_catalog($filters, $current_page, 30);
        $notice = isset($_GET['notice']) ? sanitize_key((string) $_GET['notice']) : '';
        require SOS_PLUGIN_DIR . 'admin/views-unmapped-catalog.php';
    }

    /**
     * Render collected prices placeholder page.
     */
    public function render_collected_prices_page(): void
    {
        $current_page = isset($_GET['paged']) ? max(1, absint((string) $_GET['paged'])) : 1;
        $filters = [
            'scrape_status' => isset($_GET['scrape_status']) ? sanitize_key((string) $_GET['scrape_status']) : '',
            'woo_product_id' => isset($_GET['woo_product_id']) ? absint((string) $_GET['woo_product_id']) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field((string) $_GET['date_from']) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field((string) $_GET['date_to']) : '',
        ];
        $listing = SOS_Collector::get_paginated_results($filters, $current_page, 20);
        require SOS_PLUGIN_DIR . 'admin/views-collected-prices.php';
    }

    /**
     * Render review queue placeholder page.
     */
    public function render_review_queue_page(): void
    {
        $current_page = isset($_GET['paged']) ? max(1, absint((string) $_GET['paged'])) : 1;
        $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : 'pending';
        $listing = SOS_Updater::get_paginated_review_queue($status, $current_page, 20);
        $notice = isset($_GET['notice']) ? sanitize_key((string) $_GET['notice']) : '';
        $message = isset($_GET['message']) ? urldecode((string) $_GET['message']) : '';
        require SOS_PLUGIN_DIR . 'admin/views-review-queue.php';
    }

    /**
     * Render update logs placeholder page.
     */
    public function render_update_logs_page(): void
    {
        $current_page = isset($_GET['paged']) ? max(1, absint((string) $_GET['paged'])) : 1;
        $listing = SOS_Updater::get_paginated_logs($current_page, 20);
        require SOS_PLUGIN_DIR . 'admin/views-update-logs.php';
    }

    /**
     * Render settings placeholder page.
     */
    public function render_settings_page(): void
    {
        $settings = (array) get_option('sos_settings', []);
        require SOS_PLUGIN_DIR . 'admin/views-settings.php';
    }

    /**
     * Determine plugin capability with safe fallback.
     */
    private function get_capability(): string
    {
        return current_user_can(SOS_Utils::CAPABILITY) ? SOS_Utils::CAPABILITY : 'manage_options';
    }

    /**
     * Check if current admin screen belongs to this plugin.
     */
    private function is_plugin_screen(): bool
    {
        $screen = get_current_screen();

        if (! $screen) {
            return false;
        }

        return str_contains((string) $screen->id, 'stellar-sync') || str_contains((string) $screen->id, 'sos-');
    }

    /**
     * Collect dashboard stats.
     *
     * @return array<string, int|string>
     */
    private function get_stage_one_stats(): array
    {
        global $wpdb;

        $tables = SOS_DB::all_tables();
        $stats = [
            'plugin_version'      => SOS_VERSION,
            'installed_version'   => (string) get_option(SOS_Utils::VERSION_OPTION, 'n/a'),
            'map_count'           => 0,
            'collected_count'     => 0,
            'review_pending'      => 0,
            'update_log_count'    => 0,
            'job_count'           => 0,
            'next_updater_run'    => wp_next_scheduled('sos_hourly_updater_event'),
            'dry_run_mode'        => (int) (! empty(((array) get_option('sos_settings', []))['dry_run_mode'])),
            'auto_update_enabled' => (int) (! empty(((array) get_option('sos_settings', []))['auto_update_enabled'])),
        ];

        if (SOS_DB::all_tables_exist()) {
            $stats['map_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['price_map']}");
            $stats['collected_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['price_data']}");
            $stats['review_pending'] = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$tables['price_review']} WHERE status = %s", 'pending')
            );
            $stats['update_log_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['price_updates']}");
            $stats['job_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['price_jobs']}");
        }

        return $stats;
    }

    /**
     * Return WooCommerce products that extension could not map or price.
     *
     * @param array<string, string> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, per_page: int, page: int}
     */
    private function get_unmapped_catalog(array $filters, int $page = 1, int $per_page = 30): array
    {
        global $wpdb;

        $page = max(1, $page);
        $per_page = max(1, min(100, $per_page));
        $offset = ($page - 1) * $per_page;

        $post_table = $wpdb->posts;
        $pm_status = $wpdb->postmeta;
        $source_join = $reason_join = $confidence_join = $title_join = $url_join = $at_join = '';
        $where = [
            "p.post_type = 'product'",
            "p.post_status IN ('publish', 'private', 'draft')",
            "ums.meta_key = '_sos_extension_unmapped_status'",
            "ums.meta_value <> ''",
        ];
        $args = [];

        $source_join = "LEFT JOIN {$wpdb->postmeta} ums_source ON ums_source.post_id = p.ID AND ums_source.meta_key = '_sos_extension_unmapped_source'";
        $reason_join = "LEFT JOIN {$wpdb->postmeta} ums_reason ON ums_reason.post_id = p.ID AND ums_reason.meta_key = '_sos_extension_unmapped_reason'";
        $confidence_join = "LEFT JOIN {$wpdb->postmeta} ums_confidence ON ums_confidence.post_id = p.ID AND ums_confidence.meta_key = '_sos_extension_unmapped_confidence'";
        $title_join = "LEFT JOIN {$wpdb->postmeta} ums_title ON ums_title.post_id = p.ID AND ums_title.meta_key = '_sos_extension_unmapped_source_title'";
        $url_join = "LEFT JOIN {$wpdb->postmeta} ums_url ON ums_url.post_id = p.ID AND ums_url.meta_key = '_sos_extension_unmapped_source_url'";
        $at_join = "LEFT JOIN {$wpdb->postmeta} ums_at ON ums_at.post_id = p.ID AND ums_at.meta_key = '_sos_extension_unmapped_at'";

        if (! empty($filters['source'])) {
            $where[] = 'ums_source.meta_value = %s';
            $args[] = sanitize_key($filters['source']);
        }

        if (! empty($filters['status'])) {
            $where[] = 'ums.meta_value = %s';
            $args[] = sanitize_key($filters['status']);
        }

        if (! empty($filters['s'])) {
            $where[] = '(p.post_title LIKE %s OR CAST(p.ID AS CHAR) LIKE %s OR ums_reason.meta_value LIKE %s OR ums_title.meta_value LIKE %s)';
            $like = '%' . $wpdb->esc_like((string) $filters['s']) . '%';
            array_push($args, $like, $like, $like, $like);
        }

        $where_sql = implode(' AND ', $where);
        $base_from = "FROM {$post_table} p
            INNER JOIN {$pm_status} ums ON ums.post_id = p.ID
            {$source_join}
            {$reason_join}
            {$confidence_join}
            {$title_join}
            {$url_join}
            {$at_join}
            WHERE {$where_sql}";

        $total_sql = "SELECT COUNT(DISTINCT p.ID) {$base_from}";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($total_sql, $args)) : $wpdb->get_var($total_sql));

        $select_sql = "SELECT DISTINCT p.ID AS woo_product_id,
                p.post_title AS product_title,
                p.post_status,
                ums.meta_value AS unmapped_status,
                ums_source.meta_value AS source,
                ums_reason.meta_value AS reason,
                ums_confidence.meta_value AS confidence,
                ums_title.meta_value AS source_title,
                ums_url.meta_value AS source_url,
                ums_at.meta_value AS unmapped_at
            {$base_from}
            ORDER BY COALESCE(ums_at.meta_value, '') DESC, p.ID DESC
            LIMIT %d OFFSET %d";
        $query_args = array_merge($args, [$per_page, $offset]);
        $items = $wpdb->get_results($wpdb->prepare($select_sql, $query_args), ARRAY_A);

        return [
            'items' => is_array($items) ? $items : [],
            'total' => $total,
            'per_page' => $per_page,
            'page' => $page,
        ];
    }
}
