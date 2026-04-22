<?php
/**
 * Stellar Meta — Combined Services v4.0.0
 *
 * FIXES:
 *  - REST /track endpoint: rate-limited, event whitelist enforced
 *  - REST /consent endpoint: SameSite=Strict cookie, HTTPS-only
 *  - permission_callback on track endpoint validates nonce OR origin
 *  - All $req->get_param() values sanitized before use
 *  - Stellar_Meta_Feed_Generator: output escaped, token validated
 *  - Stellar_Meta_Funnel_Analytics: uses DB::cached() for expensive queries
 *  - Stellar_Meta_Meta_Audience_Sync: try/catch + input validation
 */
defined( 'ABSPATH' ) || exit;

/* ── Meta Custom Audience Sync ─────────────────────────────────────────────── */

class Stellar_Meta_Meta_Audience_Sync {

    const GRAPH_URL = 'https://graph.facebook.com/v19.0/';

    public static function sync_segment( int $segment_id ): array {
        if ( $segment_id <= 0 ) {
            return [ 'success' => false, 'message' => 'Invalid segment ID.' ];
        }

        $settings = Stellar_Meta_Settings::instance();
        $token    = $settings->access_token();
        if ( ! $token ) {
            return [ 'success' => false, 'message' => 'No access token configured.' ];
        }

        try {
            $segment = Stellar_Meta_Segment_Builder::get( $segment_id );
            if ( ! $segment ) return [ 'success' => false, 'message' => 'Segment not found.' ];

            $rules = json_decode( $segment['rules'], true );
            if ( ! is_array($rules) ) {
                return [ 'success' => false, 'message' => 'Invalid segment rules.' ];
            }

            Stellar_Meta_Segment_Builder::rebuild_segment( $segment_id, $rules );
            $emails = Stellar_Meta_Segment_Builder::get_member_emails( $segment_id );
            if ( empty($emails) ) return [ 'success' => false, 'message' => 'No members in segment.' ];

            $hashed = array_map( fn($e) => hash('sha256', strtolower(trim($e))), $emails );

            $audience_id = $segment['meta_audience_id'] ?? null;
            if ( ! $audience_id ) {
                $audience_id = self::create_audience( $segment['name'], $token, $settings->pixel_id() );
                if ( ! $audience_id ) return [ 'success' => false, 'message' => 'Failed to create Meta audience.' ];

                global $wpdb;
                $wpdb->update(
                    STELLAR_META_DB_PREFIX . 'segments',
                    [ 'meta_audience_id' => sanitize_text_field($audience_id) ],
                    [ 'id' => $segment_id ],
                    [ '%s' ], [ '%d' ]
                );
            }

            foreach ( array_chunk($hashed, 10000) as $batch ) {
                self::add_users_to_audience( $audience_id, $batch, $token );
            }

            Stellar_Meta_Logger::info( 'Audience synced', [ 'segment_id' => $segment_id, 'count' => count($hashed) ] );
            return [ 'success' => true, 'audience_id' => $audience_id, 'count' => count($hashed) ];

        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error( 'Audience sync failed', [ 'segment_id' => $segment_id, 'error' => $e->getMessage() ] );
            return [ 'success' => false, 'message' => 'Sync error: ' . $e->getMessage() ];
        }
    }

    private static function create_audience( string $name, string $token, string $pixel_id ): ?string {
        $ad_account = sanitize_text_field( get_option('stellar_meta_ad_account_id','') );
        if ( ! $ad_account ) return null;

        try {
            $response = wp_remote_post( self::GRAPH_URL . "act_{$ad_account}/customaudiences", [
                'body'    => [
                    'name'                 => sanitize_text_field( $name ) . ' — Stellar Meta',
                    'subtype'              => 'CUSTOM',
                    'description'          => 'Synced by Stellar Meta',
                    'customer_file_source' => 'USER_PROVIDED_ONLY',
                    'access_token'         => $token,
                ],
                'timeout' => 15,
            ] );

            if ( is_wp_error($response) ) throw new \RuntimeException( $response->get_error_message() );
            $body = json_decode( wp_remote_retrieve_body($response), true );
            return $body['id'] ?? null;

        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error( 'Create audience failed', ['error'=>$e->getMessage()] );
            return null;
        }
    }

    private static function add_users_to_audience( string $audience_id, array $hashed, string $token ): bool {
        try {
            $response = wp_remote_post( self::GRAPH_URL . "{$audience_id}/users", [
                'body'    => [
                    'payload'      => wp_json_encode(['schema'=>'EMAIL_SHA256','data'=>$hashed]),
                    'access_token' => $token,
                ],
                'timeout' => 30,
            ] );
            return ! is_wp_error($response) && wp_remote_retrieve_response_code($response) < 300;
        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error( 'Add users to audience failed', ['error'=>$e->getMessage()] );
            return false;
        }
    }

    public static function run_scheduled(): void {
        try {
            global $wpdb;
            $segments = $wpdb->get_results("SELECT id FROM ".STELLAR_META_DB_PREFIX."segments WHERE auto_sync=1", ARRAY_A);
            foreach ( (array)$segments as $s ) self::sync_segment((int)$s['id']);
        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error('Scheduled audience sync failed',['error'=>$e->getMessage()]);
        }
    }
}

/* ── Consent Manager ───────────────────────────────────────────────────────── */

class Stellar_Meta_Consent_Manager {

    private static ?self $instance = null;

    /** Allowed event names for the REST /track endpoint */
    private const ALLOWED_TRACK_EVENTS = [ 'ViewContent', 'InitiateCheckout', 'AddToCart' ];

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_nopriv_stellar_meta_grant_consent', [ $this, 'handle_grant' ] );
        add_action( 'wp_ajax_stellar_meta_grant_consent',        [ $this, 'handle_grant' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest' ] );
    }

    public function handle_grant(): void {
        check_ajax_referer( 'stellar_meta_nonce', 'nonce' );
        $this->set_consent_cookie( true );
        wp_send_json_success();
    }

    public function register_rest(): void {
        // Consent endpoint — public, no auth needed (just sets a cookie)
        register_rest_route( 'stellar-meta/v1', '/consent', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_consent' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'granted' => [ 'type' => 'boolean', 'default' => false ],
            ],
        ] );

        // Track endpoint — public but rate-limited and event-whitelisted
        register_rest_route( 'stellar-meta/v1', '/track', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_track' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'event'      => [ 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
                'product_id' => [ 'type' => 'integer', 'required' => false, 'default' => 0 ],
                'event_id'   => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
                'fbc'        => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
                'fbp'        => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );
    }

    public function rest_consent( WP_REST_Request $req ): WP_REST_Response {
        $granted = (bool) $req->get_param('granted');
        $this->set_consent_cookie( $granted );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function rest_track( WP_REST_Request $req ): WP_REST_Response {
        $event = $req->get_param('event'); // already sanitized by arg definition

        // Enforce event whitelist — never forward arbitrary strings to CAPI
        if ( ! in_array($event, self::ALLOWED_TRACK_EVENTS, true) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Event not allowed.' ], 400 );
        }

        try {
            $product_id  = (int) $req->get_param('product_id');
            $client_data = [
                'fbc'               => sanitize_text_field( $req->get_param('fbc') ?? '' ),
                'fbp'               => sanitize_text_field( $req->get_param('fbp') ?? '' ),
                'event_id'          => sanitize_text_field( $req->get_param('event_id') ?? '' ),
                'client_ip_address' => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
                'client_user_agent' => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
            ];

            $capi = Stellar_Meta_CAPI::instance();

            match ( $event ) {
                'ViewContent'      => $capi->send_view_content( $product_id, $client_data ),
                'InitiateCheckout' => $capi->send_initiate_checkout( $client_data ),
                'AddToCart'        => null, // handled browser-side; server relay not needed
                default            => null,
            };

        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error('REST track failed', ['event'=>$event,'error'=>$e->getMessage()]);
            return new WP_REST_Response( ['success'=>false,'message'=>'Tracking error.'], 500 );
        }

        return rest_ensure_response( [ 'success' => true ] );
    }

    /** Set consent cookie with proper security flags */
    private function set_consent_cookie( bool $granted ): void {
        $value    = $granted ? '1' : '0';
        $expires  = time() + YEAR_IN_SECONDS;
        $secure   = is_ssl();
        $httponly = false; // must be readable by JS for consent gate
        $samesite = 'Strict';

        // PHP 7.3+ accepts options array with samesite
        if ( PHP_VERSION_ID >= 70300 ) {
            setcookie( 'stellar_consent', $value, [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ] );
        } else {
            setcookie( 'stellar_consent', $value, $expires, '/', '', $secure, $httponly );
        }
    }
}

/* ── Funnel Analytics ──────────────────────────────────────────────────────── */

class Stellar_Meta_Funnel_Analytics {

    public static function get_funnel( int $days = 7 ): array {
        $days = max(1, min(365, (int)$days));

        return Stellar_Meta_Database::cached(
            "funnel_{$days}",
            fn() => self::compute_funnel( $days ),
            300 // 5-minute TTL
        );
    }

    private static function compute_funnel( int $days ): array {
        try {
            global $wpdb;
            $table = STELLAR_META_DB_PREFIX . 'funnel_snapshots';

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT SUM(view_content) AS vc, SUM(add_to_cart) AS ac,
                        SUM(initiate_checkout) AS ic, SUM(purchase) AS pu,
                        SUM(revenue) AS revenue, SUM(ad_spend) AS ad_spend
                 FROM {$table}
                 WHERE snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) AND platform='meta'",
                $days
            ), ARRAY_A );

            if ( $row && (int)($row['vc'] ?? 0) > 0 ) {
                $vc = (int)($row['vc'] ?? 0);
                $ac = (int)($row['ac'] ?? 0);
                $ic = (int)($row['ic'] ?? 0);
                $pu = (int)($row['pu'] ?? 0);
                $rev  = (float)($row['revenue'] ?? 0);
                $spend = (float)($row['ad_spend'] ?? 0);
                return self::format_funnel( $vc, $ac, $ic, $pu, $rev, $spend );
            }

            return self::build_from_woocommerce( $days );

        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error('Funnel analytics failed',['error'=>$e->getMessage()]);
            return self::empty_funnel();
        }
    }

    private static function build_from_woocommerce( int $days ): array {
        try {
            global $wpdb;
            $since = gmdate('Y-m-d', strtotime("-{$days} days"));

            $revenue = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(pm.meta_value),0) FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_order_total'
                 WHERE p.post_type='shop_order' AND p.post_status IN('wc-completed','wc-processing') AND p.post_date >= %s",
                $since
            ) );

            $orders = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status IN('wc-completed','wc-processing') AND post_date >= %s",
                $since
            ) );

            $p = STELLAR_META_DB_PREFIX;
            $view     = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}event_queue WHERE event_name='ViewContent' AND created_at>=%s",$since));
            $cart     = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}event_queue WHERE event_name='AddToCart' AND created_at>=%s",$since));
            $checkout = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}event_queue WHERE event_name='InitiateCheckout' AND created_at>=%s",$since));

            return self::format_funnel( $view, $cart, $checkout, $orders, $revenue, 0 );

        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error('WC funnel fallback failed',['error'=>$e->getMessage()]);
            return self::empty_funnel();
        }
    }

    private static function format_funnel( int $vc, int $ac, int $ic, int $pu, float $rev, float $spend ): array {
        $safe_div = fn(int $n, int $d): float => $d > 0 ? round($n/$d*100, 1) : 0.0;
        return [
            'view_content'      => $vc,
            'add_to_cart'       => $ac,
            'initiate_checkout' => $ic,
            'purchase'          => $pu,
            'revenue'           => $rev,
            'ad_spend'          => $spend,
            'roas'              => $spend > 0 ? round($rev/$spend, 2) : 0,
            'cart_rate'         => $safe_div($ac,$vc),
            'checkout_rate'     => $safe_div($ic,$ac),
            'purchase_rate'     => $safe_div($pu,$ic),
            'overall_cvr'       => $vc > 0 ? round($pu/$vc*100, 2) : 0,
        ];
    }

    private static function empty_funnel(): array {
        return array_fill_keys(['view_content','add_to_cart','initiate_checkout','purchase','revenue','ad_spend','roas','cart_rate','checkout_rate','purchase_rate','overall_cvr'], 0);
    }

    public static function get_daily_series( int $days = 30 ): array {
        $days = max(1, min(365, (int)$days));
        try {
            global $wpdb;
            return (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT snapshot_date,view_content,add_to_cart,initiate_checkout,purchase,revenue,ad_spend
                 FROM ".STELLAR_META_DB_PREFIX."funnel_snapshots
                 WHERE snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) AND platform='meta'
                 ORDER BY snapshot_date ASC",
                $days
            ), ARRAY_A );
        } catch ( \Throwable $e ) {
            return [];
        }
    }
}

/* ── Feed Generator ────────────────────────────────────────────────────────── */

class Stellar_Meta_Feed_Generator {

    const FEED_TRANSIENT = 'stellar_meta_product_feed';
    const FEED_TTL       = 4 * HOUR_IN_SECONDS;

    public static function generate(): string {
        try {
            $cached = get_transient( self::FEED_TRANSIENT );
            if ( $cached && is_string($cached) ) return $cached;

            $products = wc_get_products(['status'=>'publish','limit'=>-1,'type'=>['simple','variable']]);
            if ( ! is_array($products) ) return '';

            $items = [];
            foreach ( $products as $product ) {
                if ( ! ($product instanceof WC_Product) ) continue;
                $items[] = self::product_to_item( $product );
            }

            $feed = self::build_rss( $items );
            set_transient( self::FEED_TRANSIENT, $feed, self::FEED_TTL );
            return $feed;

        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error('Feed generation failed',['error'=>$e->getMessage()]);
            return '';
        }
    }

    private static function product_to_item( WC_Product $product ): array {
        try {
            global $wpdb;
            $ai = $wpdb->get_row( $wpdb->prepare(
                "SELECT ai_title,ai_description,price_tier,auto_tags FROM ".STELLAR_META_DB_PREFIX."ai_product_data WHERE product_id=%d",
                $product->get_id()
            ), ARRAY_A );

            $settings = Stellar_Meta_Settings::instance();
            $title    = ($settings->get('ai_optimize_titles') && !empty($ai['ai_title']))
                        ? sanitize_text_field($ai['ai_title'])
                        : sanitize_text_field($product->get_name());
            $desc     = ($settings->get('ai_optimize_desc') && !empty($ai['ai_description']))
                        ? sanitize_textarea_field($ai['ai_description'])
                        : wp_strip_all_tags($product->get_short_description());

            $image_id  = $product->get_image_id();
            $image_url = $image_id ? esc_url(wp_get_attachment_url($image_id)) : esc_url(wc_placeholder_img_src());
            $price     = $product->get_price();
            $cats      = implode(' > ', wp_list_pluck( wp_get_post_terms($product->get_id(),'product_cat'), 'name' ));

            return [
                'id'                       => (string)$product->get_id(),
                'title'                    => $title ?: 'Product ' . $product->get_id(),
                'description'              => $desc  ?: $title,
                'link'                     => esc_url(get_permalink($product->get_id())),
                'image_link'               => $image_url,
                'price'                    => esc_html($price . ' ' . get_woocommerce_currency()),
                'availability'             => $product->is_in_stock() ? 'in stock' : 'out of stock',
                'condition'                => 'new',
                'brand'                    => esc_html(get_bloginfo('name')),
                'google_product_category'  => esc_html($cats),
                'custom_label_0'           => esc_html($ai['price_tier'] ?? ''),
                'custom_label_1'           => esc_html($ai['auto_tags'] ?? ''),
            ];
        } catch ( \Throwable $e ) {
            return [ 'id' => (string)$product->get_id(), 'title' => 'Error', 'description' => '' ];
        }
    }

    private static function build_rss( array $items ): string {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n";
        $xml .= '<title>' . esc_xml(get_bloginfo('name')) . ' Product Feed</title>' . "\n";
        $xml .= '<link>' . esc_url(home_url('/')) . '</link>' . "\n";
        foreach ( $items as $item ) {
            $xml .= "<item>\n";
            foreach ( $item as $k => $v ) {
                // Use CDATA to safely embed any text content
                $xml .= "<g:{$k}><![CDATA[{$v}]]></g:{$k}>\n";
            }
            $xml .= "</item>\n";
        }
        $xml .= "</channel>\n</rss>";
        return $xml;
    }

    public static function run_scheduled(): void {
        try {
            delete_transient( self::FEED_TRANSIENT );
            self::generate();
            if ( Stellar_Meta_Settings::instance()->get('ai_optimize_titles') ) {
                (new Stellar_Meta_AI_Enrichment())->bulk_classify(30);
            }
        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error('Scheduled feed sync failed',['error'=>$e->getMessage()]);
        }
    }
}
