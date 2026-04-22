<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) ); }
$s     = Stellar_Meta_Settings::instance();
$saved  = isset($_GET['saved']) && '1' === sanitize_text_field(wp_unslash($_GET['saved']));
$error  = isset($_GET['error']) && '1' === sanitize_text_field(wp_unslash($_GET['error']));

// Credential validation — run on every page load to surface misconfigured values
$cred_errors = $s->validate_credentials();

function sm_toggle(string $key, Stellar_Meta_Settings $s, bool $default=false): void {
    $checked = $s->get($key, $default) ? 'checked' : '';
    echo "<label class=\"sm-toggle\"><input type=\"checkbox\" name=\"{$key}\" value=\"1\" {$checked}><span class=\"sm-toggle-slider\"></span></label>";
}

// Secret fields show masked placeholder when value exists
function sm_secret(string $key, string $label, string $ph, string $desc, Stellar_Meta_Settings $s): void {
    $has_val = $s->get($key,'') !== '';
    $display = $has_val ? '••••••••' : '';
    echo "<div class=\"sm-form-field\">";
    echo "<label for=\"{$key}\">{$label}</label>";
    echo "<div style=\"position:relative\">";
    echo "<input class=\"sm-input\" type=\"password\" id=\"{$key}\" name=\"{$key}\" value=\"" . esc_attr($display) . "\" placeholder=\"{$ph}\" autocomplete=\"new-password\">";
    if($has_val) echo "<span style=\"position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:10px;color:var(--sm-green)\">✓ Saved</span>";
    echo "</div>";
    echo "<span class=\"sm-desc\">{$desc}" . ($has_val?" Leave blank to keep current value.":" ") . "</span>";
    echo "</div>";
}

function sm_field(string $key, string $label, string $ph, string $desc, string $type, Stellar_Meta_Settings $s): void {
    echo "<div class=\"sm-form-field\"><label for=\"{$key}\">{$label}</label>";
    echo "<input class=\"sm-input\" type=\"{$type}\" id=\"{$key}\" name=\"{$key}\" value=\"" . esc_attr($s->get($key,'')) . "\" placeholder=\"{$ph}\">";
    echo "<span class=\"sm-desc\">{$desc}</span></div>";
}
?>
<div class="sm-header">
  <div class="sm-header-left"><div class="sm-logo">S</div><div>
    <div class="sm-header-title">Settings</div>
    <div class="sm-header-sub">Configure Stellar Meta v3 — all settings persist correctly</div>
  </div></div>
</div>
<div class="sm-content">
  <?php if($error??false):?><div class="sm-notice sm-notice-error"><?php esc_html_e('Save failed — check error log.','stellar-meta');?></div><?php endif;?>
  <?php if($saved): ?><div class="sm-notice sm-notice-success">✓ Settings saved successfully.</div><?php endif; ?>
  <?php if ( ! empty( $cred_errors ) ) : ?>
  <div class="sm-notice sm-notice-warning" style="margin-bottom:16px">
    <strong><?php esc_html_e( '⚠ Credential validation issues detected:', 'stellar-meta' ); ?></strong>
    <ul style="margin:8px 0 0 18px;padding:0">
      <?php foreach ( $cred_errors as $err ) : ?>
        <li><?php echo esc_html( $err['message'] ); ?> <em style="color:var(--sm-muted)">(<?php echo esc_html( $err['field'] ); ?>)</em></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('stellar_meta_settings'); ?>
    <input type="hidden" name="action" value="stellar_meta_save_settings">

    <!-- Meta Credentials -->
    <div class="sm-section-title">Meta Credentials</div>
    <div class="sm-card" style="margin-bottom:20px"><div class="sm-card-body"><div class="sm-form-grid">
      <?php sm_field('pixel_id','Meta Pixel ID','1234567890','Your 15-digit Meta Pixel ID from Events Manager','text',$s); ?>
      <?php sm_secret('access_token','CAPI Access Token','EAAGm…','System user token with ads_management + page permissions',$s); ?>
      <?php sm_field('test_event_code','Test Event Code','TEST12345','Use during testing. Remove before going live.','text',$s); ?>
      <?php sm_secret('openai_api_key','OpenAI API Key','sk-…','Required for AI enrichment, LTV prediction, and churn scoring',$s); ?>
    </div></div></div>

    <!-- Google / GA4 / Ads -->
    <div class="sm-section-title" id="v3-google">Google Analytics &amp; Ads</div>
    <div class="sm-card" style="margin-bottom:20px"><div class="sm-card-body"><div class="sm-form-grid">
      <?php sm_field('ga4_measurement_id','GA4 Measurement ID','G-XXXXXXXXXX','Your Google Analytics 4 property ID','text',$s); ?>
      <?php sm_secret('ga4_api_secret','GA4 API Secret (Server-Side)','','GA4 Admin → Data Streams → Measurement Protocol API secrets',$s); ?>
      <?php sm_field('google_ads_id','Google Ads Customer ID','123-456-7890','Your 10-digit Google Ads customer ID','text',$s); ?>
      <?php sm_field('gads_conversion_label','Enhanced Conv. Action Name','Purchase','Conversion action name for Enhanced Conversions API','text',$s); ?>
      <?php sm_field('tiktok_pixel_id','TikTok Pixel ID','','From TikTok Ads Manager → Assets → Events','text',$s); ?>
    </div></div></div>

    <!-- Tracking toggles -->
    <div class="sm-section-title" id="v3-tracking">Tracking &amp; Live Features</div>
    <div class="sm-card" style="margin-bottom:20px"><div class="sm-card-body">
      <?php
      $tracking_items = [
        ['pixel_enabled',         'Meta Pixel (Browser)',           'Inject Meta Pixel base code on all pages',                           true],
        ['capi_enabled',          'Meta CAPI (Server-Side)',         'Send events server-side via Conversions API with retry queue',        true],
        ['ga4_server_enabled',    'GA4 Server-Side (MP v2)',         'Send purchase + refund events from PHP — bypasses ad blockers',      true],
        ['ga4_eec_enabled',       'GA4 Enhanced Ecommerce',          'Full item-level schema: view_item_list, add_to_wishlist, refund',     true],
        ['gads_enhanced_enabled', 'Google Ads Enhanced Conversions', 'Server-side hashed conversions — improves attribution 10–20%',      false],
        ['live_dashboard_enabled','Live Dashboard',                  'Enable real-time event streaming in the ⚡ Live dashboard',           true],
        ['exit_intent_enabled',   'Exit Intent Events',              'Fire Meta + GA4 events on exit intent — retarget within seconds',    true],
        ['bid_scorer_enabled',    'Real-Time Bid Scorer',            'Score visitors 0–100 and pass as Meta custom_data.bid_signal',       false],
        ['ai_enrichment',         'AI Product Enrichment',           'GPT-4o enriches CAPI events with product purchase-type signals',     false],
      ];
      foreach ($tracking_items as [$key,$label,$desc,$default]): ?>
      <div class="sm-settings-toggle-row">
        <div class="sm-settings-toggle-info"><strong><?php echo esc_html($label) ?></strong><span><?php echo esc_html($desc) ?></span></div>
        <?php sm_toggle($key, $s, $default); ?>
      </div>
      <?php endforeach; ?>
      <hr class="sm-sep">
      <div class="sm-form-grid">
        <div class="sm-form-field">
          <label>Live Dashboard Refresh (seconds)</label>
          <input class="sm-input" type="number" name="live_refresh_seconds" value="<?php echo esc_attr($s->live_refresh_seconds()) ?>" min="10" max="300">
          <span class="sm-desc">How often the live dashboard polls for new data.</span>
        </div>
      </div>
    </div></div>

    <!-- AI Intelligence -->
    <div class="sm-section-title" id="v3-ai">AI Intelligence</div>
    <div class="sm-card" style="margin-bottom:20px"><div class="sm-card-body">
      <?php
      $ai_items = [
        ['ltv_enabled',              'LTV Prediction Engine',     'GPT-4o predicts 90-day + 12-month LTV — feeds Meta Value Optimization', false],
        ['churn_enabled',            'Churn Prediction',          'Daily RFM scoring — auto-populates win-back Meta audiences',             false],
        ['cohort_enabled',           'Cohort Analytics',          'Monthly retention curves and cohort revenue',                            true],
        ['heatmap_enabled',          'Revenue Heatmap',           'Day × hour revenue grid for ad scheduling optimisation',                 true],
        ['product_analytics_enabled','Product Analytics',         'Per-product CVR, revenue, trending, and cross-sell pairs',              true],
      ];
      foreach ($ai_items as [$key,$label,$desc,$default]): ?>
      <div class="sm-settings-toggle-row">
        <div class="sm-settings-toggle-info"><strong><?php echo esc_html($label) ?></strong><span><?php echo esc_html($desc) ?></span></div>
        <?php sm_toggle($key, $s, $default); ?>
      </div>
      <?php endforeach; ?>
      <hr class="sm-sep">
      <div class="sm-form-grid">
        <div class="sm-form-field">
          <label>Min Orders for LTV Scoring</label>
          <input class="sm-input" type="number" name="ltv_min_orders" value="<?php echo esc_attr($s->ltv_min_orders()) ?>" min="1" max="10">
          <span class="sm-desc">Only score customers with at least this many orders.</span>
        </div>
        <div class="sm-form-field">
          <label>Churn High-Risk Threshold (days)</label>
          <input class="sm-input" type="number" name="churn_high_risk_days" value="<?php echo esc_attr($s->churn_high_risk_days()) ?>" min="7" max="365">
          <span class="sm-desc">Customers silent for this many days = high risk.</span>
        </div>
      </div>
    </div></div>

    <!-- Anomaly Alerts -->
    <div class="sm-section-title" id="v3-alerts">Anomaly Detection &amp; Alerts</div>
    <div class="sm-card" style="margin-bottom:20px"><div class="sm-card-body">
      <div class="sm-settings-toggle-row">
        <div class="sm-settings-toggle-info"><strong>Anomaly Detection</strong><span>AI monitors ROAS, conversion volume, and pixel health every 15 minutes</span></div>
        <?php sm_toggle('anomaly_enabled', $s, true); ?>
      </div>
      <hr class="sm-sep">
      <div class="sm-form-grid">
        <?php sm_field('anomaly_alert_email','Alert Email',get_option('admin_email'),'Receive email alerts when anomalies are detected','email',$s); ?>
        <?php sm_secret('anomaly_slack_webhook','Slack Webhook URL','https://hooks.slack.com/…','Paste your Slack Incoming Webhook URL for instant alerts',$s); ?>
        <div class="sm-form-field">
          <label>ROAS Drop Alert Threshold (%)</label>
          <input class="sm-input" type="number" name="anomaly_roas_drop_pct" value="<?php echo esc_attr($s->anomaly_roas_drop_pct()) ?>" min="5" max="90">
          <span class="sm-desc">Alert when today's ROAS drops more than this % below 14-day baseline.</span>
        </div>
      </div>
    </div></div>

    <!-- Privacy -->
    <div class="sm-section-title">Privacy &amp; Consent</div>
    <div class="sm-card" style="margin-bottom:20px"><div class="sm-card-body">
      <?php
      $privacy_items = [
        ['gdpr_mode',   'GDPR Consent Mode',     'Gate pixel firing behind explicit user consent',           true],
        ['ccpa_enabled','CCPA Compliance',        'Enable California Consumer Privacy Act opt-out support',  true],
        ['pii_hashing', 'PII Hashing (SHA-256)', 'Hash all user data before sending to Meta/Google APIs',   true],
        ['lazy_pixel',  'Lazy Pixel Load',        'Defer pixel load for better Core Web Vitals scores',      true],
      ];
      foreach ($privacy_items as [$key,$label,$desc,$default]): ?>
      <div class="sm-settings-toggle-row">
        <div class="sm-settings-toggle-info"><strong><?php echo esc_html($label) ?></strong><span><?php echo esc_html($desc) ?></span></div>
        <?php sm_toggle($key, $s, $default); ?>
      </div>
      <?php endforeach; ?>
    </div></div>

    <!-- CAPI Engine -->
    <div class="sm-section-title">CAPI Engine</div>
    <div class="sm-card" style="margin-bottom:24px"><div class="sm-card-body"><div class="sm-form-grid">
      <div class="sm-form-field">
        <label>Max Retries</label>
        <input class="sm-input" type="number" name="capi_max_retries" value="<?php echo esc_attr($s->capi_max_retries()) ?>" min="1" max="5">
        <span class="sm-desc">Retry failed CAPI events this many times (1–5).</span>
      </div>
      <div class="sm-form-field">
        <label>Retry Backoff (seconds)</label>
        <input class="sm-input" type="number" name="capi_retry_backoff" value="<?php echo esc_attr($s->capi_retry_backoff()) ?>" min="5">
        <span class="sm-desc">Base backoff — grows exponentially on each retry.</span>
      </div>
      <div class="sm-form-field">
        <label>Log Retention (days)</label>
        <input class="sm-input" type="number" name="capi_log_retention" value="<?php echo esc_attr($s->capi_log_retention_days()) ?>" min="7">
        <span class="sm-desc">Days to keep event queue records in the database.</span>
      </div>
      <div class="sm-form-field">
        <label>Deduplication Window (hours)</label>
        <input class="sm-input" type="number" name="dedup_window_hours" value="<?php echo esc_attr($s->dedup_window_hours()) ?>" min="1">
        <span class="sm-desc">Block duplicate events within this window.</span>
      </div>
    </div></div></div>

    <div style="display:flex;gap:12px;align-items:center">
      <button type="submit" class="sm-btn sm-btn-primary">Save All Settings</button>
      <span style="font-size:11px;color:var(--sm-muted)">Stellar Meta v<?php echo STELLAR_META_VERSION ?></span>
    </div>
  </form>
</div>
