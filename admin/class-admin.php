<?php
/**
 * Stellar Meta — Admin Controller v4.0.0
 * SECURITY HARDENING: centralised ajax_gate(), capability checks on ALL handlers,
 * allowlisted render(), try/catch everywhere, deep JSON sanitization, GDPR export/erase.
 */
defined( 'ABSPATH' ) || exit;

class Stellar_Meta_Admin {

    private static ?self $instance = null;
    private Stellar_Meta_Settings $settings;

    private const ALLOWED_PAGES = [
        'dashboard','live-dashboard','products','tracking','capi',
        'audiences','catalog','analytics','intelligence','alerts','privacy','settings',
    ];

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->settings = Stellar_Meta_Settings::instance();
        $this->hooks();
    }

    private function hooks(): void {
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_stellar_meta_save_settings', [ $this, 'handle_save_settings' ] );
        foreach ( [
            'get_stats','sync_segment','create_segment','delete_segment','run_ai_batch',
            'get_events','save_event_map','generate_feed','run_ltv_batch','run_churn_batch',
            'rebuild_heatmap','rebuild_cohorts','live_poll',
        ] as $a ) {
            add_action( "wp_ajax_stellar_meta_{$a}", [ $this, "ajax_{$a}" ] );
        }
        add_filter( 'plugin_action_links_' . STELLAR_META_BASENAME, [ $this, 'action_links' ] );
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_data_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers',   [ $this, 'register_data_eraser'   ] );
    }

    /** Centralised gate: nonce check + capability check. Sends JSON error on failure. */
    private function ajax_gate( string $cap = 'manage_woocommerce' ): bool {
        if ( ! check_ajax_referer( 'stellar_meta_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 ); return false;
        }
        if ( ! current_user_can( $cap ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 ); return false;
        }
        return true;
    }

    public function register_menus(): void {
        add_menu_page( __('Stellar Meta','stellar-meta'), __('Stellar Meta','stellar-meta'),
            'manage_woocommerce', 'stellar-meta', [ $this, 'page_dashboard' ], $this->menu_icon(), 56 );
        foreach ( [
            ['stellar-meta',              'Dashboard',    'page_dashboard'   ],
            ['stellar-meta-live',         '⚡ Live',       'page_live'        ],
            ['stellar-meta-products',     'Products',     'page_products'    ],
            ['stellar-meta-tracking',     'Tracking',     'page_tracking'    ],
            ['stellar-meta-capi',         'CAPI Engine',  'page_capi'        ],
            ['stellar-meta-audiences',    'Audiences',    'page_audiences'   ],
            ['stellar-meta-catalog',      'Product Feed', 'page_catalog'     ],
            ['stellar-meta-analytics',    'Analytics',    'page_analytics'   ],
            ['stellar-meta-intelligence', 'Intelligence', 'page_intelligence'],
            ['stellar-meta-alerts',       'Alerts',       'page_alerts'      ],
            ['stellar-meta-privacy',      'Privacy',      'page_privacy'     ],
            ['stellar-meta-settings',     'Settings',     'page_settings'    ],
        ] as [ $slug, $title, $cb ] ) {
            add_submenu_page( 'stellar-meta', __($title,'stellar-meta'), __($title,'stellar-meta'),
                'manage_woocommerce', $slug, [ $this, $cb ] );
        }
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'stellar-meta' ) === false ) return;
        wp_enqueue_style(  'stellar-meta-admin', STELLAR_META_URL.'admin/css/admin.css',   [], STELLAR_META_VERSION );
        wp_enqueue_script( 'stellar-meta-admin', STELLAR_META_URL.'admin/js/admin.js', ['jquery','wp-util'], STELLAR_META_VERSION, true );
        wp_localize_script( 'stellar-meta-admin', 'StellarMetaAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('stellar_meta_admin_nonce'),
            'feedUrl' => esc_url( home_url('?stellar-meta-feed=1') ),
            'i18n'    => [ 'confirm_delete' => __('Delete this segment? This cannot be undone.','stellar-meta') ],
        ] );
        wp_enqueue_script( 'chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', [], '4.4.1', true );
    }

    public function page_dashboard():    void { $this->render('dashboard');     }
    public function page_live():         void { $this->render('live-dashboard'); }
    public function page_products():     void { $this->render('products');       }
    public function page_tracking():     void { $this->render('tracking');       }
    public function page_capi():         void { $this->render('capi');           }
    public function page_audiences():    void { $this->render('audiences');      }
    public function page_catalog():      void { $this->render('catalog');        }
    public function page_analytics():    void { $this->render('analytics');      }
    public function page_intelligence(): void { $this->render('intelligence');   }
    public function page_alerts():       void { $this->render('alerts');         }
    public function page_privacy():      void { $this->render('privacy');        }
    public function page_settings():     void { $this->render('settings');       }

    /** SECURITY: allowlisted slug + capability check before file include */
    private function render( string $page ): void {
        if ( ! in_array( $page, self::ALLOWED_PAGES, true ) ) {
            wp_die( esc_html__( 'Invalid page.', 'stellar-meta' ) );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) );
        }
        $file = STELLAR_META_DIR . 'admin/views/pages/' . $page . '.php';
        echo '<div class="stellar-meta-wrap">';
        if ( file_exists( $file ) ) {
            include $file;
        } else {
            echo '<p>' . esc_html__( 'Page not found.', 'stellar-meta' ) . '</p>';
        }
        echo '</div>';
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public function handle_save_settings(): void {
        check_admin_referer( 'stellar_meta_settings' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );
        try {
            $this->settings->update_from_post( (array) wp_unslash( $_POST ) );
            $this->settings->save();
            Stellar_Meta_Logger::info( 'Settings saved', [ 'user_id' => get_current_user_id() ] );
            wp_safe_redirect( add_query_arg( ['page'=>'stellar-meta-settings','saved'=>1], admin_url('admin.php') ) );
        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error( 'Settings save failed', [ 'error' => $e->getMessage() ] );
            wp_safe_redirect( add_query_arg( ['page'=>'stellar-meta-settings','error'=>1], admin_url('admin.php') ) );
        }
        exit;
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    public function ajax_get_stats(): void {
        if ( ! $this->ajax_gate() ) return;
        try { wp_send_json_success( Stellar_Meta_Event_Queue::stats() ); }
        catch ( \Throwable $e ) { wp_send_json_error( ['message'=>'Failed to retrieve stats.'], 500 ); }
    }

    public function ajax_get_events(): void {
        if ( ! $this->ajax_gate() ) return;
        try { wp_send_json_success( Stellar_Meta_Event_Queue::recent_events(20) ); }
        catch ( \Throwable $e ) { wp_send_json_error( ['message'=>'Failed to retrieve events.'], 500 ); }
    }

    public function ajax_live_poll(): void {
        if ( ! $this->ajax_gate() ) return;
        try {
            wp_send_json_success([
                'metrics'  => Stellar_Meta_Live_Stats::get_live_metrics(),
                'funnel'   => Stellar_Meta_Live_Stats::get_live_funnel(),
                'activity' => Stellar_Meta_Live_Stats::get_activity_feed(10),
                'events'   => Stellar_Meta_Live_Stats::get_live_events(20),
                'ts'       => time(),
            ]);
        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error( 'ajax_live_poll', ['error'=>$e->getMessage()] );
            wp_send_json_error( ['message'=>'Live poll failed.'], 500 );
        }
    }

    public function ajax_sync_segment(): void {
        if ( ! $this->ajax_gate() ) return;
        $id = (int) sanitize_text_field( wp_unslash( $_POST['segment_id'] ?? '0' ) );
        if ( $id <= 0 ) { wp_send_json_error(['message'=>'Invalid segment ID.'], 400); return; }
        try { wp_send_json_success( Stellar_Meta_Meta_Audience_Sync::sync_segment($id) ); }
        catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error( 'Sync segment failed', ['id'=>$id,'error'=>$e->getMessage()] );
            wp_send_json_error( ['message'=>'Sync failed: '.$e->getMessage()], 500 );
        }
    }

    public function ajax_create_segment(): void {
        if ( ! $this->ajax_gate() ) return;
        $name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $desc  = sanitize_textarea_field( wp_unslash( $_POST['desc'] ?? '' ) );
        if ( empty($name) ) { wp_send_json_error(['message'=>'Name required.'], 400); return; }
        $rules = json_decode( wp_unslash( $_POST['rules'] ?? '{}' ), true );
        if ( ! is_array($rules) ) { wp_send_json_error(['message'=>'Invalid rules JSON.'], 400); return; }
        $rules = array_map( fn($v) => is_numeric($v) ? (float)$v : sanitize_text_field((string)$v), $rules );
        try {
            $id = Stellar_Meta_Segment_Builder::create( $name, $desc, $rules );
            wp_send_json_success( ['id'=>$id] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( ['message'=>'Failed to create segment.'], 500 );
        }
    }

    public function ajax_delete_segment(): void {
        if ( ! $this->ajax_gate() ) return;
        $id = (int) sanitize_text_field( wp_unslash( $_POST['segment_id'] ?? '0' ) );
        if ( $id <= 0 ) { wp_send_json_error(['message'=>'Invalid ID.'], 400); return; }
        try { Stellar_Meta_Segment_Builder::delete($id); wp_send_json_success(); }
        catch ( \Throwable $e ) { wp_send_json_error(['message'=>'Delete failed.'], 500); }
    }

    public function ajax_run_ai_batch(): void {
        if ( ! $this->ajax_gate() ) return;
        try { wp_send_json_success( (new Stellar_Meta_AI_Enrichment())->bulk_classify(20) ); }
        catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error('AI batch failed', ['error'=>$e->getMessage()]);
            wp_send_json_error(['message'=>'AI batch failed: '.$e->getMessage()], 500);
        }
    }

    public function ajax_save_event_map(): void {
        if ( ! $this->ajax_gate() ) return;
        $raw = json_decode( wp_unslash( $_POST['mapping'] ?? '{}' ), true );
        if ( ! is_array($raw) ) { wp_send_json_error(['message'=>'Invalid JSON.'], 400); return; }
        $allowed = ['Purchase','AddToCart','ViewContent','InitiateCheckout'];
        $clean = [];
        foreach ( $raw as $event => $params ) {
            $event = sanitize_text_field($event);
            if ( ! in_array($event, $allowed, true) || ! is_array($params) ) continue;
            $clean[$event] = array_map('sanitize_text_field', $params);
        }
        try { $this->settings->set('custom_event_mappings',$clean); $this->settings->save(); wp_send_json_success(); }
        catch ( \Throwable $e ) { wp_send_json_error(['message'=>'Save failed.'], 500); }
    }

    public function ajax_generate_feed(): void {
        if ( ! $this->ajax_gate() ) return;
        try {
            delete_transient('stellar_meta_product_feed');
            Stellar_Meta_Feed_Generator::generate();
            wp_send_json_success(['url'=>esc_url(home_url('?stellar-meta-feed=1'))]);
        } catch ( \Throwable $e ) { wp_send_json_error(['message'=>'Feed generation failed.'], 500); }
    }

    public function ajax_run_ltv_batch(): void {
        if ( ! $this->ajax_gate() ) return;
        try { Stellar_Meta_LTV_Predictor::run_batch(30); wp_send_json_success(['message'=>'LTV scoring complete.']); }
        catch ( \Throwable $e ) { wp_send_json_error(['message'=>'LTV batch failed.'], 500); }
    }

    public function ajax_run_churn_batch(): void {
        if ( ! $this->ajax_gate() ) return;
        try { Stellar_Meta_Churn_Predictor::run_batch(50); wp_send_json_success(['message'=>'Churn scoring complete.']); }
        catch ( \Throwable $e ) { wp_send_json_error(['message'=>'Churn batch failed.'], 500); }
    }

    public function ajax_rebuild_heatmap(): void {
        if ( ! $this->ajax_gate() ) return; // FIX: was missing capability check
        try { Stellar_Meta_Revenue_Heatmap::rebuild(); wp_send_json_success(['message'=>'Heatmap rebuilt.']); }
        catch ( \Throwable $e ) { wp_send_json_error(['message'=>'Rebuild failed.'], 500); }
    }

    public function ajax_rebuild_cohorts(): void {
        if ( ! $this->ajax_gate() ) return; // FIX: was missing capability check
        try { Stellar_Meta_Cohort_Analytics::rebuild(); wp_send_json_success(['message'=>'Cohorts rebuilt.']); }
        catch ( \Throwable $e ) { wp_send_json_error(['message'=>'Rebuild failed.'], 500); }
    }

    // ── GDPR Export ───────────────────────────────────────────────────────────

    public function register_data_exporter( array $e ): array {
        $e['stellar-meta'] = ['exporter_friendly_name'=>__('Stellar Meta Data','stellar-meta'),'callback'=>[$this,'export_personal_data']];
        return $e;
    }
    public function register_data_eraser( array $e ): array {
        $e['stellar-meta'] = ['eraser_friendly_name'=>__('Stellar Meta Data','stellar-meta'),'callback'=>[$this,'erase_personal_data']];
        return $e;
    }

    public function export_personal_data( string $email, int $page = 1 ): array {
        $user = get_user_by( 'email', sanitize_email($email) );
        if ( ! $user ) return ['data'=>[],'done'=>true];
        global $wpdb; $p = STELLAR_META_DB_PREFIX; $uid = (int)$user->ID;
        $items = [];
        $ltv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ltv_scores WHERE customer_id=%d",$uid),ARRAY_A);
        if ($ltv) $items[] = ['group_id'=>'stellar-ltv','group_label'=>'LTV Score','item_id'=>"ltv-{$uid}",'data'=>[
            ['name'=>'Predicted 90d LTV','value'=>wc_price($ltv['predicted_90d'])],
            ['name'=>'LTV Tier','value'=>esc_html($ltv['ltv_tier'])],
        ]];
        $churn = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}churn_scores WHERE customer_id=%d",$uid),ARRAY_A);
        if ($churn) $items[] = ['group_id'=>'stellar-churn','group_label'=>'Churn Risk','item_id'=>"churn-{$uid}",'data'=>[
            ['name'=>'Risk Level','value'=>esc_html($churn['churn_risk'])],
        ]];
        $email_hash = hash('sha256', strtolower(trim($email)));
        $identity = $wpdb->get_row($wpdb->prepare("SELECT stellar_uid,first_seen,last_seen FROM {$p}identity_graph WHERE email_hash=%s OR customer_id=%d",$email_hash,$uid),ARRAY_A);
        if ($identity) $items[] = ['group_id'=>'stellar-identity','group_label'=>'Identity Graph','item_id'=>"id-{$uid}",'data'=>[
            ['name'=>'Stellar UID','value'=>esc_html($identity['stellar_uid'])],
            ['name'=>'First Seen','value'=>esc_html($identity['first_seen'])],
        ]];
        return ['data'=>$items,'done'=>true];
    }

    public function erase_personal_data( string $email, int $page = 1 ): array {
        $user = get_user_by( 'email', sanitize_email($email) );
        if ( ! $user ) return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
        global $wpdb; $p = STELLAR_META_DB_PREFIX; $uid = (int)$user->ID;
        $email_hash = hash('sha256', strtolower(trim($email)));
        $n = 0;
        $n += (int)$wpdb->delete("{$p}ltv_scores",   ['customer_id'=>$uid],['%d']);
        $n += (int)$wpdb->delete("{$p}churn_scores", ['customer_id'=>$uid],['%d']);
        $n += (int)$wpdb->delete("{$p}touchpoints",  ['customer_id'=>$uid],['%d']);
        $n += (int)$wpdb->query($wpdb->prepare("DELETE FROM {$p}identity_graph WHERE email_hash=%s OR customer_id=%d",$email_hash,$uid));
        $n += (int)$wpdb->delete("{$p}segment_members",['customer_id'=>$uid],['%d']);
        $wpdb->update("{$p}audit_log",['user_id'=>null,'ip_address'=>'0.0.0.0'],['user_id'=>$uid],['%s','%s'],['%d']);
        Stellar_Meta_Logger::info('GDPR erasure',['uid'=>$uid]);
        return ['items_removed'=>$n>0,'items_retained'=>false,'messages'=>[sprintf(__('Removed %d Stellar Meta records.','stellar-meta'),$n)],'done'=>true];
    }

    public function action_links( array $links ): array {
        array_unshift($links,'<a href="'.esc_url(admin_url('admin.php?page=stellar-meta-settings')).'">'.esc_html__('Settings','stellar-meta').'</a>');
        return $links;
    }
    private function menu_icon(): string {
        return 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M10 2L12.4 7.5H18L13.5 11L15.3 17L10 13.5L4.7 17L6.5 11L2 7.5H7.6L10 2Z"/></svg>');
    }
}
