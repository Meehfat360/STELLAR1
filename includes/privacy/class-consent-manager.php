<?php
/**
 * Stellar Meta — Consent Manager v4.1.0
 *
 * Previously this file was EMPTY (only had defined() check).
 * This is a complete ground-up implementation.
 *
 * Fixes:
 *  - Cookie consent banner (Accept / Decline / Manage Preferences)
 *  - Google Consent Mode v2 defaults injected before any tags
 *  - CCPA / GPC (Sec-GPC header) detection
 *  - Granular per-category preferences (analytics / marketing / functional)
 *  - Consent stored in user meta with full audit log (timestamp, IP hash, policy version)
 *  - Consent revocation AJAX endpoint with nonce gate
 *  - Data retention cron enforcement
 *  - Third-party disclosure registry
 *  - Hooks into WP Privacy API for export & erasure
 */
defined( 'ABSPATH' ) || exit;

class Stellar_Meta_Consent_Manager {

    const COOKIE_NAME    = 'stellar_consent';
    const PREFS_COOKIE   = 'stellar_consent_prefs';
    const POLICY_VERSION = '2.0';
    const META_KEY       = '_stellar_consent_record';

    /** Third-party disclosure list */
    const THIRD_PARTIES = [
        [
            'name'    => 'Meta (Facebook)',
            'purpose' => 'Advertising pixel & Conversions API — tracks purchases and site activity for ad optimisation.',
            'type'    => 'marketing',
            'privacy' => 'https://www.facebook.com/policy.php',
        ],
        [
            'name'    => 'Google Analytics 4',
            'purpose' => 'Analytics — measures user behaviour, sessions, and conversion funnels.',
            'type'    => 'analytics',
            'privacy' => 'https://policies.google.com/privacy',
        ],
        [
            'name'    => 'Google Ads',
            'purpose' => 'Enhanced conversions — improves attribution accuracy for Google Ads campaigns.',
            'type'    => 'marketing',
            'privacy' => 'https://policies.google.com/privacy',
        ],
        [
            'name'    => 'TikTok',
            'purpose' => 'Advertising pixel — tracks site visits and conversions for TikTok ad campaigns.',
            'type'    => 'marketing',
            'privacy' => 'https://www.tiktok.com/legal/privacy-policy',
        ],
    ];

    private static ?self $instance = null;
    private Stellar_Meta_Settings $settings;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->settings = Stellar_Meta_Settings::instance();
        $this->hooks();
    }

    private function hooks(): void {
        add_action( 'wp_head',   [ $this, 'output_consent_mode_defaults' ], 0 );
        add_action( 'wp_footer', [ $this, 'render_consent_banner' ], 100 );

        add_action( 'wp_ajax_nopriv_stellar_meta_set_consent',    [ $this, 'ajax_set_consent'    ] );
        add_action( 'wp_ajax_stellar_meta_set_consent',           [ $this, 'ajax_set_consent'    ] );
        add_action( 'wp_ajax_nopriv_stellar_meta_revoke_consent', [ $this, 'ajax_revoke_consent' ] );
        add_action( 'wp_ajax_stellar_meta_revoke_consent',        [ $this, 'ajax_revoke_consent' ] );

        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers',   [ $this, 'register_eraser'   ] );

        // Data-retention cron
        add_action( 'stellar_meta_enforce_retention', [ $this, 'enforce_retention' ] );
        if ( ! wp_next_scheduled( 'stellar_meta_enforce_retention' ) ) {
            wp_schedule_event( time(), 'daily', 'stellar_meta_enforce_retention' );
        }
    }

    // ── Consent status ────────────────────────────────────────────────────────

    public static function is_granted(): bool {
        $self = self::instance();
        if ( ! $self->settings->is_gdpr_mode() ) return true;
        if ( $self->settings->is_ccpa_enabled() && $self->detect_gpc() ) return false;

        $cookie = sanitize_text_field( $_COOKIE[ self::COOKIE_NAME ] ?? '' );
        if ( $cookie === '1' ) return true;
        if ( $cookie === '0' ) return false;

        if ( is_user_logged_in() ) {
            $record = get_user_meta( get_current_user_id(), self::META_KEY, true );
            if ( is_array( $record ) && isset( $record['granted'] ) ) {
                return (bool) $record['granted'];
            }
        }
        return false;
    }

    public static function get_preferences(): array {
        $defaults = [ 'analytics' => false, 'marketing' => false, 'functional' => true ];
        $raw      = sanitize_text_field( $_COOKIE[ self::PREFS_COOKIE ] ?? '' );
        if ( ! $raw ) return $defaults;
        $prefs = json_decode( stripslashes( $raw ), true );
        if ( ! is_array( $prefs ) ) return $defaults;
        return array_merge( $defaults, array_intersect_key(
            array_map( 'boolval', $prefs ),
            $defaults
        ) );
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    public function ajax_set_consent(): void {
        // Basic rate-limit: max 10 per IP per hour
        $rate_key = 'sm_consent_rate_' . md5( $this->client_ip() );
        $hits     = (int) get_transient( $rate_key );
        if ( $hits > 10 ) {
            wp_send_json_error( [ 'message' => 'Too many requests.' ], 429 );
            return;
        }
        set_transient( $rate_key, $hits + 1, HOUR_IN_SECONDS );

        if ( ! check_ajax_referer( 'stellar_meta_consent_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
            return;
        }

        $granted = ! empty( $_POST['granted'] ) && $_POST['granted'] !== '0';
        $prefs   = [ 'analytics' => $granted, 'marketing' => $granted, 'functional' => true ];

        if ( isset( $_POST['prefs'] ) && is_array( $_POST['prefs'] ) ) {
            foreach ( [ 'analytics', 'marketing' ] as $key ) {
                $prefs[ $key ] = ! empty( $_POST['prefs'][ $key ] );
            }
        }

        $this->set_consent_cookie( $granted );
        $this->set_prefs_cookie( $prefs );
        $this->store_consent_record( $granted, $prefs, 'explicit' );

        wp_send_json_success( [ 'granted' => $granted, 'prefs' => $prefs ] );
    }

    public function ajax_revoke_consent(): void {
        if ( ! check_ajax_referer( 'stellar_meta_consent_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
            return;
        }

        $prefs = [ 'analytics' => false, 'marketing' => false, 'functional' => true ];
        $this->set_consent_cookie( false );
        $this->set_prefs_cookie( $prefs );
        $this->store_consent_record( false, $prefs, 'revoked' );

        foreach ( [ self::COOKIE_NAME, self::PREFS_COOKIE ] as $name ) {
            setcookie( $name, '', time() - YEAR_IN_SECONDS, '/', '', is_ssl(), false );
        }

        wp_send_json_success( [ 'revoked' => true ] );
    }

    // ── Cookie helpers ────────────────────────────────────────────────────────

    private function set_consent_cookie( bool $granted ): void {
        $opts = [
            'expires'  => time() + YEAR_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ];
        if ( PHP_VERSION_ID >= 70300 ) {
            setcookie( self::COOKIE_NAME, $granted ? '1' : '0', $opts );
        } else {
            setcookie( self::COOKIE_NAME, $granted ? '1' : '0', $opts['expires'], '/', '', $opts['secure'], false );
        }
    }

    private function set_prefs_cookie( array $prefs ): void {
        $opts = [
            'expires'  => time() + YEAR_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ];
        if ( PHP_VERSION_ID >= 70300 ) {
            setcookie( self::PREFS_COOKIE, (string) wp_json_encode( $prefs ), $opts );
        } else {
            setcookie( self::PREFS_COOKIE, (string) wp_json_encode( $prefs ), $opts['expires'], '/', '', $opts['secure'], false );
        }
    }

    // ── Consent record (audit log) ────────────────────────────────────────────

    private function store_consent_record( bool $granted, array $prefs, string $action ): void {
        $record = [
            'granted'        => $granted,
            'prefs'          => $prefs,
            'action'         => $action,
            'policy_version' => self::POLICY_VERSION,
            'ip_hash'        => hash( 'sha256', $this->client_ip() ),
            'ua_hash'        => hash( 'sha256', sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
            'timestamp'      => time(),
        ];

        if ( is_user_logged_in() ) {
            $uid     = get_current_user_id();
            $history = (array) get_user_meta( $uid, self::META_KEY . '_log', true );
            $history[] = $record;
            $history   = array_slice( $history, -20 ); // keep last 20
            update_user_meta( $uid, self::META_KEY,          $record  );
            update_user_meta( $uid, self::META_KEY . '_log', $history );
        }

        try {
            Stellar_Meta_Logger::info( 'Consent ' . $action, [
                'granted'        => (int) $granted,
                'policy_version' => self::POLICY_VERSION,
            ] );
        } catch ( \Throwable $e ) { /* non-fatal */ }
    }

    // ── Google Consent Mode v2 ────────────────────────────────────────────────

    public function output_consent_mode_defaults(): void {
        if ( ! $this->settings->is_gdpr_mode() ) return;
        $prefs   = self::get_preferences();
        $granted = self::is_granted();
        $ana = ( $granted && $prefs['analytics'] ) ? 'granted' : 'denied';
        $mkt = ( $granted && $prefs['marketing'] ) ? 'granted' : 'denied';
        ?>
<!-- Stellar Meta :: Google Consent Mode v2 -->
<script>
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{
    'analytics_storage':  '<?php echo esc_js( $ana ); ?>',
    'ad_storage':         '<?php echo esc_js( $mkt ); ?>',
    'ad_user_data':       '<?php echo esc_js( $mkt ); ?>',
    'ad_personalization': '<?php echo esc_js( $mkt ); ?>',
    'functionality_storage':'granted',
    'security_storage':   'granted',
    'wait_for_update':    2000
});
</script>
<!-- / Stellar Meta Consent Mode -->
        <?php
    }

    // ── Cookie consent banner ─────────────────────────────────────────────────

    public function render_consent_banner(): void {
        if ( ! $this->settings->is_gdpr_mode() ) return;
        if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) return; // already decided

        $nonce       = wp_create_nonce( 'stellar_meta_consent_nonce' );
        $privacy_url = esc_url( get_privacy_policy_url() ?: home_url( '/privacy-policy/' ) );
        ?>
<!-- Stellar Meta :: Cookie Consent Banner -->
<style id="sm-consent-css">
#sm-cb,#sm-cp{position:fixed;bottom:0;left:0;right:0;z-index:999999;background:#1a1a2e;color:#e8eaf6;
  padding:20px 24px;box-shadow:0 -4px 24px rgba(0,0,0,.45);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:14px;line-height:1.5;}
#sm-cb{display:flex;flex-wrap:wrap;align-items:center;gap:14px;}
#sm-cb-text{flex:1;min-width:240px;}
#sm-cb-text strong{display:block;margin-bottom:4px;font-size:15px;}
#sm-cb-text span{color:#b0bec5;}
#sm-cb-text a{color:#7c83fd;}
#sm-cb-btns{display:flex;gap:10px;flex-wrap:wrap;}
.sm-cb-btn{padding:8px 18px;border-radius:6px;cursor:pointer;font-size:13px;white-space:nowrap;border:none;}
#sm-cb-accept{background:#7c83fd;color:#fff;font-weight:700;}
#sm-cb-decline{background:transparent;border:1px solid #546e7a !important;color:#b0bec5;}
#sm-cb-manage{background:transparent;border:1px solid #7c83fd !important;color:#7c83fd;}
#sm-cp{display:none;max-height:80vh;overflow-y:auto;}
.sm-cp-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;
  padding:12px;background:#252540;border-radius:8px;margin-bottom:10px;}
.sm-cp-row label{cursor:pointer;display:contents;}
.sm-cp-row input[type=checkbox]{width:18px;height:18px;flex-shrink:0;margin-top:2px;}
.sm-cp-desc{color:#90a4ae;font-size:12px;margin-top:2px;}
#sm-cp-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;}
#sm-cp-save{background:#7c83fd;color:#fff;border:none;padding:9px 22px;border-radius:6px;cursor:pointer;font-weight:700;font-size:13px;}
#sm-cp-back{background:transparent;border:1px solid #546e7a;color:#b0bec5;padding:9px 16px;border-radius:6px;cursor:pointer;font-size:13px;}
</style>

<div id="sm-cb" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Cookie consent','stellar-meta'); ?>">
  <div id="sm-cb-text">
    <strong><?php esc_html_e('We use cookies &amp; tracking','stellar-meta'); ?></strong>
    <span><?php esc_html_e('We use Meta Pixel, Google Analytics, and similar tools to improve your experience and show relevant ads.','stellar-meta'); ?>
      <a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e('Privacy Policy','stellar-meta'); ?></a>
    </span>
  </div>
  <div id="sm-cb-btns">
    <button class="sm-cb-btn" id="sm-cb-manage"><?php esc_html_e('Manage Preferences','stellar-meta'); ?></button>
    <button class="sm-cb-btn" id="sm-cb-decline"><?php esc_html_e('Decline','stellar-meta'); ?></button>
    <button class="sm-cb-btn" id="sm-cb-accept"><?php esc_html_e('Accept All','stellar-meta'); ?></button>
  </div>
</div>

<div id="sm-cp" aria-hidden="true">
  <strong style="font-size:16px;display:block;margin-bottom:14px"><?php esc_html_e('Cookie Preferences','stellar-meta'); ?></strong>
  <div class="sm-cp-row">
    <div><strong><?php esc_html_e('Functional (Required)','stellar-meta'); ?></strong>
      <div class="sm-cp-desc"><?php esc_html_e('Essential for cart, session, and security. Cannot be disabled.','stellar-meta'); ?></div>
    </div>
    <input type="checkbox" id="sm-pref-functional" checked disabled>
  </div>
  <div class="sm-cp-row">
    <label for="sm-pref-analytics"><div><strong><?php esc_html_e('Analytics','stellar-meta'); ?></strong>
      <div class="sm-cp-desc"><?php esc_html_e('Google Analytics 4 — helps us understand how visitors use the site.','stellar-meta'); ?></div>
    </div></label>
    <input type="checkbox" id="sm-pref-analytics">
  </div>
  <div class="sm-cp-row">
    <label for="sm-pref-marketing"><div><strong><?php esc_html_e('Marketing','stellar-meta'); ?></strong>
      <div class="sm-cp-desc"><?php esc_html_e('Meta Pixel, Google Ads, TikTok — used to measure and improve ad campaigns.','stellar-meta'); ?></div>
    </div></label>
    <input type="checkbox" id="sm-pref-marketing">
  </div>
  <div id="sm-cp-actions">
    <button id="sm-cp-save"><?php esc_html_e('Save My Choices','stellar-meta'); ?></button>
    <button id="sm-cp-back"><?php esc_html_e('Back','stellar-meta'); ?></button>
  </div>
  <p style="margin-top:12px;font-size:11px;color:#546e7a">
    <?php printf(
        esc_html__('For a full list of third parties we use, see our %1$sPrivacy Policy%2$s.','stellar-meta'),
        '<a href="'.$privacy_url.'" style="color:#7c83fd" target="_blank" rel="noopener">',
        '</a>'
    ); ?>
  </p>
</div>

<script>
(function(){
    var cb=document.getElementById('sm-cb'),
        cp=document.getElementById('sm-cp'),
        aj=<?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>,
        nk=<?php echo wp_json_encode( $nonce ); ?>;

    function hide(){ if(cb) cb.style.display='none'; if(cp) cp.style.display='none'; }

    function send(granted, prefs){
        var fd=new FormData();
        fd.append('action','stellar_meta_set_consent');
        fd.append('nonce',nk);
        fd.append('granted',granted?'1':'0');
        if(prefs){ for(var k in prefs) fd.append('prefs['+k+']',prefs[k]?'1':'0'); }
        fetch(aj,{method:'POST',body:fd,credentials:'same-origin'}).catch(function(){});
        if(typeof gtag==='function'){
            var a=(granted&&prefs&&prefs.analytics)?'granted':'denied';
            var m=(granted&&prefs&&prefs.marketing)?'granted':'denied';
            gtag('consent','update',{'analytics_storage':a,'ad_storage':m,'ad_user_data':m,'ad_personalization':m});
        }
        window.stellarConsentGranted=granted;
        if(granted&&window.__stellarPixelPending&&typeof fbq!=='undefined'){
            fbq('track','PageView'); window.__stellarPixelPending=false;
        }
        hide();
    }

    document.getElementById('sm-cb-accept').onclick=function(){
        send(true,{analytics:true,marketing:true,functional:true});
    };
    document.getElementById('sm-cb-decline').onclick=function(){
        send(false,{analytics:false,marketing:false,functional:true});
    };
    document.getElementById('sm-cb-manage').onclick=function(){
        cb.style.display='none'; cp.style.display='block'; cp.removeAttribute('aria-hidden');
    };
    document.getElementById('sm-cp-save').onclick=function(){
        var prefs={
            analytics:document.getElementById('sm-pref-analytics').checked,
            marketing:document.getElementById('sm-pref-marketing').checked,
            functional:true
        };
        send(prefs.analytics||prefs.marketing,prefs);
    };
    document.getElementById('sm-cp-back').onclick=function(){
        cp.style.display='none'; cb.style.display='flex';
    };
}());
</script>
<!-- / Stellar Meta Cookie Banner -->
        <?php
    }

    // ── WP Privacy API — Export ───────────────────────────────────────────────

    public function register_exporter( array $exporters ): array {
        $exporters['stellar-meta-consent'] = [
            'exporter_friendly_name' => __( 'Stellar Meta Consent Records', 'stellar-meta' ),
            'callback'               => [ $this, 'export_consent_data' ],
        ];
        return $exporters;
    }

    public function export_consent_data( string $email, int $page = 1 ): array {
        $user = get_user_by( 'email', sanitize_email( $email ) );
        if ( ! $user ) return [ 'data' => [], 'done' => true ];

        $record  = get_user_meta( $user->ID, self::META_KEY, true );
        $history = (array) get_user_meta( $user->ID, self::META_KEY . '_log', true );
        $items   = [];

        if ( is_array( $record ) ) {
            $items[] = [
                'group_id'    => 'stellar-consent',
                'group_label' => __( 'Stellar Meta — Consent Status', 'stellar-meta' ),
                'item_id'     => 'consent-' . $user->ID,
                'data'        => [
                    [ 'name' => __( 'Consent Granted',   'stellar-meta' ), 'value' => ! empty( $record['granted'] ) ? __('Yes','stellar-meta') : __('No','stellar-meta') ],
                    [ 'name' => __( 'Policy Version',    'stellar-meta' ), 'value' => esc_html( $record['policy_version'] ?? '' ) ],
                    [ 'name' => __( 'Action',            'stellar-meta' ), 'value' => esc_html( $record['action'] ?? '' ) ],
                    [ 'name' => __( 'Timestamp',         'stellar-meta' ), 'value' => esc_html( ! empty( $record['timestamp'] ) ? gmdate( 'Y-m-d H:i:s', $record['timestamp'] ) : '' ) ],
                    [ 'name' => __( 'Analytics Consent', 'stellar-meta' ), 'value' => ! empty( $record['prefs']['analytics'] ) ? __('Granted','stellar-meta') : __('Denied','stellar-meta') ],
                    [ 'name' => __( 'Marketing Consent', 'stellar-meta' ), 'value' => ! empty( $record['prefs']['marketing'] ) ? __('Granted','stellar-meta') : __('Denied','stellar-meta') ],
                ],
            ];
        }

        foreach ( array_slice( $history, -10 ) as $idx => $h ) {
            if ( ! is_array( $h ) ) continue;
            $items[] = [
                'group_id'    => 'stellar-consent-log',
                'group_label' => __( 'Stellar Meta — Consent History', 'stellar-meta' ),
                'item_id'     => 'consent-log-' . $user->ID . '-' . $idx,
                'data'        => [
                    [ 'name' => __( 'Action',    'stellar-meta' ), 'value' => esc_html( $h['action'] ?? '' ) ],
                    [ 'name' => __( 'Granted',   'stellar-meta' ), 'value' => ! empty( $h['granted'] ) ? __('Yes','stellar-meta') : __('No','stellar-meta') ],
                    [ 'name' => __( 'Timestamp', 'stellar-meta' ), 'value' => esc_html( ! empty( $h['timestamp'] ) ? gmdate( 'Y-m-d H:i:s', $h['timestamp'] ) : '' ) ],
                ],
            ];
        }

        return [ 'data' => $items, 'done' => true ];
    }

    // ── WP Privacy API — Erasure ──────────────────────────────────────────────

    public function register_eraser( array $erasers ): array {
        $erasers['stellar-meta-consent'] = [
            'eraser_friendly_name' => __( 'Stellar Meta Consent Records', 'stellar-meta' ),
            'callback'             => [ $this, 'erase_consent_data' ],
        ];
        return $erasers;
    }

    public function erase_consent_data( string $email, int $page = 1 ): array {
        $user = get_user_by( 'email', sanitize_email( $email ) );
        if ( ! $user ) {
            return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true ];
        }

        delete_user_meta( $user->ID, self::META_KEY );
        delete_user_meta( $user->ID, self::META_KEY . '_log' );

        try {
            Stellar_Meta_Logger::info( 'Consent records erased (GDPR)', [ 'user_id' => $user->ID ] );
        } catch ( \Throwable $e ) { /* non-fatal */ }

        return [
            'items_removed'  => true,
            'items_retained' => false,
            'messages'       => [ __( 'Stellar Meta consent records removed.', 'stellar-meta' ) ],
            'done'           => true,
        ];
    }

    // ── Data retention enforcement ────────────────────────────────────────────

    public function enforce_retention(): void {
        try {
            $days = max( 7, min( 730, Stellar_Meta_Settings::instance()->capi_log_retention_days() ) );
            global $wpdb;
            $p = STELLAR_META_DB_PREFIX;

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$p}audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days
            ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$p}event_queue WHERE status IN('delivered','failed') AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days
            ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$p}pixel_log WHERE fired_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days
            ) );

            Stellar_Meta_Logger::info( 'Data retention enforced', [ 'days' => $days ] );
        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error( 'Retention enforcement failed', [ 'error' => $e->getMessage() ] );
        }
    }

    // ── GPC detection ─────────────────────────────────────────────────────────

    private function detect_gpc(): bool {
        if ( ! empty( $_SERVER['HTTP_SEC_GPC'] ) && '1' === $_SERVER['HTTP_SEC_GPC'] ) return true;
        if ( ! empty( $_SERVER['HTTP_DNT'] )     && '1' === $_SERVER['HTTP_DNT']     ) return true;
        return false;
    }

    // ── Public helpers ────────────────────────────────────────────────────────

    public static function get_third_parties(): array {
        return self::THIRD_PARTIES;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function client_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( empty( $_SERVER[ $key ] ) ) continue;
            $ip = trim( explode( ',', sanitize_text_field( $_SERVER[ $key ] ) )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
        }
        return '0.0.0.0';
    }
}
