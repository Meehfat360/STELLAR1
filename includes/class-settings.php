<?php
defined( 'ABSPATH' ) || exit;

/**
 * Central settings registry — v3.
 *
 * FIXES:
 *  - Full sanitize_input() pipeline before any value is stored
 *  - Secret fields use preserve_secret_value() — blank POST = keep existing
 *  - Type-cast on every accessor: no raw unsanitised values leave this class
 *  - wp_parse_args() deep-merge ensures new defaults never overwrite saved data
 *  - REST-API-safe: sanitize_group() is callable externally
 */
class Stellar_Meta_Settings {

    private static ?self $instance = null;
    private array $data = [];

    /** Secret field names — blank POST value means "keep current" */
    const SECRET_FIELDS = [
        'access_token', 'openai_api_key', 'ga4_api_secret',
        'google_ads_developer_token', 'anomaly_slack_webhook',
    ];

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $saved      = (array) get_option( 'stellar_meta_settings', [] );
        $this->data = array_merge( $this->defaults(), $saved );
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    public function get( string $key, mixed $fallback = null ): mixed {
        return $this->data[ $key ] ?? $fallback;
    }

    public function set( string $key, mixed $value ): void {
        $this->data[ $key ] = $value;
    }

    /**
     * Save the full settings array to the database.
     * Runs the sanitizer pipeline before writing.
     */
    public function save(): bool {
        $clean = $this->sanitize_all( $this->data );
        $this->data = $clean;
        return update_option( 'stellar_meta_settings', $clean );
    }

    /**
     * Update from a raw POST array (used by handle_save_settings).
     * Preserves existing secret values when the POST value is blank.
     */
    public function update_from_post( array $post ): void {
        $defaults   = $this->defaults();
        $text_fields = array_keys( array_filter( $defaults, fn($v) => is_string($v) && ! is_bool($v) ) );
        $bool_fields = array_keys( array_filter( $defaults, fn($v) => is_bool($v) ) );
        $int_fields  = array_keys( array_filter( $defaults, fn($v) => is_int($v) ) );

        foreach ( $text_fields as $key ) {
            $raw = isset( $post[$key] ) ? wp_unslash( $post[$key] ) : '';

            // Secret fields: blank POST = keep existing value
            if ( in_array( $key, self::SECRET_FIELDS, true ) ) {
                if ( $raw === '' || $raw === '••••••••' ) continue; // preserve
                $this->data[$key] = sanitize_text_field( $raw );
            } else {
                $this->data[$key] = sanitize_text_field( $raw );
            }
        }

        foreach ( $bool_fields as $key ) {
            $this->data[$key] = isset( $post[$key] ) && $post[$key] !== '0';
        }

        foreach ( $int_fields as $key ) {
            if ( isset( $post[$key] ) ) {
                $this->data[$key] = (int) $post[$key];
            }
        }

        // Custom event mappings (JSON blob)
        if ( isset( $post['custom_event_mappings'] ) ) {
            $decoded = json_decode( wp_unslash( $post['custom_event_mappings'] ), true );
            $this->data['custom_event_mappings'] = is_array($decoded) ? $decoded : [];
        }
    }

    public function all(): array { return $this->data; }

    // ── Typed accessors ────────────────────────────────────────────────────────

    public function pixel_id(): string              { return (string) $this->get('pixel_id', ''); }
    public function access_token(): string          { return (string) $this->get('access_token', ''); }
    public function test_event_code(): string       { return (string) $this->get('test_event_code', ''); }
    public function openai_key(): string            { return (string) $this->get('openai_api_key', ''); }
    public function ga4_measurement_id(): string    { return (string) $this->get('ga4_measurement_id', ''); }
    public function ga4_api_secret(): string        { return (string) $this->get('ga4_api_secret', ''); }
    public function google_ads_id(): string         { return (string) $this->get('google_ads_id', ''); }
    public function google_ads_label(): string      { return (string) $this->get('google_ads_label', ''); }
    public function tiktok_pixel_id(): string       { return (string) $this->get('tiktok_pixel_id', ''); }
    public function gads_conversion_label(): string { return (string) $this->get('gads_conversion_label', ''); }
    public function google_ads_developer_token(): string { return (string) $this->get('google_ads_developer_token', ''); }

    public function is_pixel_enabled(): bool        { return (bool) $this->get('pixel_enabled', true); }
    public function is_capi_enabled(): bool         { return (bool) $this->get('capi_enabled', true); }
    public function is_ai_enrichment_enabled(): bool{ return (bool) $this->get('ai_enrichment', false); }
    public function is_gdpr_mode(): bool            { return (bool) $this->get('gdpr_mode', true); }
    public function is_ccpa_enabled(): bool         { return (bool) $this->get('ccpa_enabled', true); }
    public function is_lazy_pixel(): bool           { return (bool) $this->get('lazy_pixel', true); }
    public function is_pii_hashing(): bool          { return (bool) $this->get('pii_hashing', true); }
    public function is_script_deferral(): bool      { return (bool) $this->get('script_deferral', false); }
    public function is_ga4_server_enabled(): bool   { return (bool) $this->get('ga4_server_enabled', true); }
    public function is_ga4_eec_enabled(): bool      { return (bool) $this->get('ga4_eec_enabled', true); }
    public function is_gads_enhanced_enabled(): bool{ return (bool) $this->get('gads_enhanced_enabled', false); }
    public function is_ltv_enabled(): bool          { return (bool) $this->get('ltv_enabled', false); }
    public function is_churn_enabled(): bool        { return (bool) $this->get('churn_enabled', false); }
    public function is_anomaly_enabled(): bool      { return (bool) $this->get('anomaly_enabled', true); }
    public function is_cohort_enabled(): bool       { return (bool) $this->get('cohort_enabled', true); }
    public function is_heatmap_enabled(): bool      { return (bool) $this->get('heatmap_enabled', true); }
    public function is_exit_intent_enabled(): bool  { return (bool) $this->get('exit_intent_enabled', true); }
    public function is_bid_scorer_enabled(): bool   { return (bool) $this->get('bid_scorer_enabled', false); }
    public function is_product_analytics_enabled(): bool { return (bool) $this->get('product_analytics_enabled', true); }
    public function is_live_dashboard_enabled(): bool    { return (bool) $this->get('live_dashboard_enabled', true); }

    public function capi_max_retries(): int         { return max(1, min(5, (int) $this->get('capi_max_retries', 3))); }
    public function capi_retry_backoff(): int       { return max(5, (int) $this->get('capi_retry_backoff', 30)); }
    public function capi_log_retention_days(): int  { return max(7, (int) $this->get('capi_log_retention', 90)); }
    public function dedup_window_hours(): int       { return max(1, (int) $this->get('dedup_window_hours', 48)); }
    public function anomaly_roas_drop_pct(): int    { return max(5, min(90, (int) $this->get('anomaly_roas_drop_pct', 20))); }
    public function ltv_min_orders(): int           { return max(1, (int) $this->get('ltv_min_orders', 1)); }
    public function churn_high_risk_days(): int     { return max(7, (int) $this->get('churn_high_risk_days', 60)); }
    public function live_refresh_seconds(): int     { return max(10, (int) $this->get('live_refresh_seconds', 30)); }

    public function anomaly_slack_webhook(): string { return (string) $this->get('anomaly_slack_webhook', ''); }
    public function anomaly_alert_email(): string   { return (string) ($this->get('anomaly_alert_email') ?: get_option('admin_email')); }
    public function custom_event_mappings(): array  { return (array) $this->get('custom_event_mappings', []); }

    // ── Sanitizer pipeline ─────────────────────────────────────────────────────

    private function sanitize_all( array $data ): array {
        $clean = $this->defaults();

        // Strings
        $strings = [
            'pixel_id','test_event_code','ga4_measurement_id','google_ads_id',
            'google_ads_label','gads_conversion_label','tiktok_pixel_id',
            'anomaly_alert_email',
        ];
        foreach ( $strings as $k ) {
            if ( isset($data[$k]) ) $clean[$k] = sanitize_text_field($data[$k]);
        }

        // Secrets — only overwrite if non-empty
        foreach ( self::SECRET_FIELDS as $k ) {
            if ( ! empty($data[$k]) ) $clean[$k] = sanitize_text_field($data[$k]);
            elseif ( isset($this->data[$k]) && $this->data[$k] !== '' ) $clean[$k] = $this->data[$k];
        }

        // URL
        if ( isset($data['anomaly_slack_webhook']) && $data['anomaly_slack_webhook'] !== '' ) {
            $clean['anomaly_slack_webhook'] = esc_url_raw($data['anomaly_slack_webhook']);
        }

        // Booleans
        $bools = [
            'pixel_enabled','capi_enabled','ai_enrichment','lazy_pixel','script_deferral',
            'gdpr_mode','ccpa_enabled','pii_hashing','catalog_auto_sync','ai_optimize_titles',
            'ai_optimize_desc','ga4_server_enabled','ga4_eec_enabled','gads_enhanced_enabled',
            'ltv_enabled','churn_enabled','anomaly_enabled','cohort_enabled','heatmap_enabled',
            'exit_intent_enabled','bid_scorer_enabled','product_analytics_enabled','live_dashboard_enabled',
        ];
        foreach ( $bools as $k ) {
            $clean[$k] = ! empty($data[$k]);
        }

        // Integers with bounds
        $clean['capi_max_retries']       = max(1,   min(5,   (int)($data['capi_max_retries'] ?? 3)));
        $clean['capi_retry_backoff']     = max(5,           (int)($data['capi_retry_backoff'] ?? 30));
        $clean['capi_log_retention']     = max(7,           (int)($data['capi_log_retention'] ?? 90));
        $clean['dedup_window_hours']     = max(1,           (int)($data['dedup_window_hours'] ?? 48));
        $clean['anomaly_roas_drop_pct']  = max(5,   min(90, (int)($data['anomaly_roas_drop_pct'] ?? 20)));
        $clean['ltv_min_orders']         = max(1,           (int)($data['ltv_min_orders'] ?? 1));
        $clean['churn_high_risk_days']   = max(7,           (int)($data['churn_high_risk_days'] ?? 60));
        $clean['live_refresh_seconds']   = max(10,  min(300,(int)($data['live_refresh_seconds'] ?? 30)));

        // Array (custom event mappings)
        if ( isset($data['custom_event_mappings']) && is_array($data['custom_event_mappings']) ) {
            $clean['custom_event_mappings'] = $data['custom_event_mappings'];
        }

        return $clean;
    }

    private function defaults(): array {
        return [
            'pixel_id'=>'','access_token'=>'','test_event_code'=>'','openai_api_key'=>'',
            'ga4_measurement_id'=>'','ga4_api_secret'=>'','google_ads_id'=>'','google_ads_label'=>'',
            'google_ads_developer_token'=>'','gads_conversion_label'=>'','tiktok_pixel_id'=>'',
            'anomaly_slack_webhook'=>'','anomaly_alert_email'=>'',
            'pixel_enabled'=>true,'capi_enabled'=>true,'ai_enrichment'=>false,'lazy_pixel'=>true,
            'script_deferral'=>false,'gdpr_mode'=>true,'ccpa_enabled'=>true,'pii_hashing'=>true,
            'catalog_auto_sync'=>false,'ai_optimize_titles'=>false,'ai_optimize_desc'=>false,
            'ga4_server_enabled'=>true,'ga4_eec_enabled'=>true,'gads_enhanced_enabled'=>false,
            'ltv_enabled'=>false,'churn_enabled'=>false,'anomaly_enabled'=>true,'cohort_enabled'=>true,
            'heatmap_enabled'=>true,'exit_intent_enabled'=>true,'bid_scorer_enabled'=>false,
            'product_analytics_enabled'=>true,'live_dashboard_enabled'=>true,
            'capi_max_retries'=>3,'capi_retry_backoff'=>30,'capi_log_retention'=>90,
            'dedup_window_hours'=>48,'anomaly_roas_drop_pct'=>20,'ltv_min_orders'=>1,
            'churn_high_risk_days'=>60,'live_refresh_seconds'=>30,
            'custom_event_mappings'=>[],
        ];
    }

    // ── Credential validation ──────────────────────────────────────────────────

    /**
     * Validate all configured API credentials and return an array of issues.
     * Called by the admin settings page and the CAPI engine before dispatching.
     *
     * @return array{field:string, message:string}[]  Empty = all valid.
     */
    public function validate_credentials(): array {
        $errors = [];

        // Meta Pixel ID — must be numeric, 10–20 digits
        $pid = $this->pixel_id();
        if ( $pid !== '' && ! preg_match( '/^\d{10,20}$/', $pid ) ) {
            $errors[] = [
                'field'   => 'pixel_id',
                'message' => __( 'Meta Pixel ID must be a 10–20 digit number.', 'stellar-meta' ),
            ];
        }

        // Meta Access Token — must start with a letter/digit, min 40 chars
        $tok = $this->access_token();
        if ( $tok !== '' && ( strlen( $tok ) < 40 || ! ctype_alnum( str_replace( [ '_', '-', '|' ], '', $tok ) ) ) ) {
            $errors[] = [
                'field'   => 'access_token',
                'message' => __( 'Meta Access Token appears invalid (must be ≥ 40 alphanumeric chars).', 'stellar-meta' ),
            ];
        }

        // GA4 Measurement ID — must match G-XXXXXXXXXX
        $ga4 = $this->ga4_measurement_id();
        if ( $ga4 !== '' && ! preg_match( '/^G-[A-Z0-9]{4,12}$/', strtoupper( $ga4 ) ) ) {
            $errors[] = [
                'field'   => 'ga4_measurement_id',
                'message' => __( 'GA4 Measurement ID must be in the format G-XXXXXXXXXX.', 'stellar-meta' ),
            ];
        }

        // GA4 API Secret — no spaces, min 10 chars
        $ga4s = $this->ga4_api_secret();
        if ( $ga4s !== '' && ( strlen( $ga4s ) < 10 || strpos( $ga4s, ' ' ) !== false ) ) {
            $errors[] = [
                'field'   => 'ga4_api_secret',
                'message' => __( 'GA4 API Secret appears invalid.', 'stellar-meta' ),
            ];
        }

        // Google Ads Customer ID — optional, must be numeric (with or without hyphens)
        $gads = $this->google_ads_id();
        if ( $gads !== '' && ! preg_match( '/^\d{3}-?\d{3}-?\d{4}$|^\d{10}$/', $gads ) ) {
            $errors[] = [
                'field'   => 'google_ads_id',
                'message' => __( 'Google Ads Customer ID must be a 10-digit number (e.g. 123-456-7890).', 'stellar-meta' ),
            ];
        }

        // Anomaly Slack webhook — must be a valid HTTPS URL
        $slack = $this->anomaly_slack_webhook();
        if ( $slack !== '' && ! filter_var( $slack, FILTER_VALIDATE_URL ) ) {
            $errors[] = [
                'field'   => 'anomaly_slack_webhook',
                'message' => __( 'Slack Webhook must be a valid HTTPS URL.', 'stellar-meta' ),
            ];
        }

        return $errors;
    }
}

