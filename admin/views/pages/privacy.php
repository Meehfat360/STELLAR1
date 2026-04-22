<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) ); }
$s = Stellar_Meta_Settings::instance();
?>
<div class="sm-header">
  <div class="sm-header-left">
    <div class="sm-logo">S</div>
    <div>
      <div class="sm-header-title">Privacy & Compliance</div>
      <div class="sm-header-sub">GDPR · CCPA · Consent Mode · Cookieless Tracking</div>
    </div>
  </div>
  <div class="sm-header-right">
    <?php if($s->is_gdpr_mode()): ?>
    <span class="sm-badge sm-badge-green"><span class="sm-dot"></span> GDPR Active</span>
    <?php endif; ?>
    <?php if($s->is_ccpa_enabled()): ?>
    <span class="sm-badge sm-badge-blue"><span class="sm-dot"></span> CCPA Active</span>
    <?php endif; ?>
  </div>
</div>

<div class="sm-content">

  <div class="sm-grid-4" style="margin-bottom:20px">
    <?php $metrics = [
      [ 'GDPR Mode',      $s->is_gdpr_mode()     ? 'Active' : 'Off', $s->is_gdpr_mode()     ? 'var(--sm-green)' : 'var(--sm-red)',  'Blocks pixels until consent' ],
      [ 'CCPA Support',   $s->is_ccpa_enabled()  ? 'Active' : 'Off', $s->is_ccpa_enabled()  ? 'var(--sm-green)' : 'var(--sm-muted)', 'Respects opt-out signals' ],
      [ 'CAPI Fallback',  $s->is_capi_enabled()  ? 'Active' : 'Off', $s->is_capi_enabled()  ? 'var(--sm-blue)'  : 'var(--sm-muted)', 'Server-side when pixel blocked' ],
      [ 'PII Hashing',    $s->is_pii_hashing()   ? 'SHA-256' : 'Off',$s->is_pii_hashing()   ? 'var(--sm-gold2)' : 'var(--sm-red)',   'Hashes all personal data' ],
    ];
    foreach($metrics as [$label,$val,$color,$desc]): ?>
    <div class="sm-metric" style="border-top-color:<?php echo esc_attr($color);?>">
      <div class="sm-metric-label"><?php echo esc_html($label);?></div>
      <div class="sm-metric-value" style="font-size:18px;color:<?php echo esc_attr($color);?>"><?php echo esc_html($val);?></div>
      <div class="sm-metric-delta flat"><?php echo esc_html($desc);?></div>
    </div>
    <?php endforeach;?>
  </div>

  <div class="sm-grid-2">
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">Privacy Controls</span>
        <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-settings'));?>" class="sm-card-action">Edit in Settings →</a>
      </div>
      <div class="sm-card-body">
        <?php $toggles = [
          ['GDPR Consent Gate',     'Blocks ALL tracking until visitor grants consent.',      $s->is_gdpr_mode()],
          ['CCPA Opt-Out Support',  'Honours GPC / Do Not Sell signals automatically.',       $s->is_ccpa_enabled()],
          ['Cookieless CAPI',       'Server-side events fire even when cookies are blocked.', $s->is_capi_enabled()],
          ['PII SHA-256 Hashing',   'Hashes email, phone, name, zip before sending to Meta.',$s->is_pii_hashing()],
          ['Lazy-Load Pixel',       'Pixel fires after first user gesture — improves Core Web Vitals.', $s->is_lazy_pixel()],
          ['Script Deferral',       'Non-critical tracking scripts load after LCP.',           $s->is_script_deferral()],
        ];
        foreach($toggles as [$label,$desc,$on]): ?>
        <div class="sm-toggle-row">
          <div class="sm-toggle-info">
            <div class="sm-toggle-label"><?php echo esc_html($label);?></div>
            <div class="sm-toggle-desc"><?php echo esc_html($desc);?></div>
          </div>
          <span class="sm-badge <?php echo $on ? 'sm-badge-green' : 'sm-badge-muted';?>"><?php echo $on ? esc_html__('ON','stellar-meta') : esc_html__('OFF','stellar-meta');?></span>
        </div>
        <?php endforeach;?>
      </div>
    </div>

    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">Consent Mode Integration</span></div>
      <div class="sm-card-body">
        <div class="sm-notice sm-notice-info" style="margin-bottom:16px">
          Stellar Meta implements Google Consent Mode v2 and Meta Consent Mode. Events are gated by consent status and routed to server-side CAPI as a privacy-preserving fallback.
        </div>
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--sm-muted);font-family:'DM Mono',monospace;margin-bottom:10px">Consent API Endpoint</div>
        <div class="sm-code"><?php echo esc_html(rest_url('stellar-meta/v1/consent'));?></div>
        <div style="margin-top:14px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--sm-muted);font-family:'DM Mono',monospace;margin-bottom:10px">CAPI Bridge Endpoint</div>
        <div class="sm-code"><?php echo esc_html(rest_url('stellar-meta/v1/track'));?></div>
        <div class="sm-notice sm-notice-success" style="margin-top:14px">
          ✓ iOS 14.5+ compatible — CAPI delivers purchase signals without relying on browser cookies or pixel.
        </div>
      </div>
    </div>
  </div>

  <!-- Third-party Disclosures -->
  <div class="sm-card" style="margin-top:20px">
    <div class="sm-card-header">
      <span class="sm-card-title">Third-Party Data Recipients</span>
    </div>
    <div class="sm-card-body">
      <p style="color:var(--sm-muted);font-size:12px;margin-bottom:16px">
        The following third parties may receive personal data (hashed where required) when tracking is active and the user has consented.
      </p>
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
          <tr style="border-bottom:1px solid var(--sm-border);color:var(--sm-muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em">
            <th style="padding:6px 10px;text-align:left">Service</th>
            <th style="padding:6px 10px;text-align:left">Category</th>
            <th style="padding:6px 10px;text-align:left">Purpose</th>
            <th style="padding:6px 10px;text-align:left">Privacy Policy</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ( Stellar_Meta_Consent_Manager::get_third_parties() as $tp ) :
            $type_color = $tp['type'] === 'marketing' ? 'var(--sm-gold2)' : 'var(--sm-blue)'; ?>
          <tr style="border-bottom:1px solid var(--sm-border)">
            <td style="padding:10px;font-weight:600"><?php echo esc_html( $tp['name'] ); ?></td>
            <td style="padding:10px"><span class="sm-badge" style="color:<?php echo esc_attr($type_color);?>"><?php echo esc_html( ucfirst( $tp['type'] ) ); ?></span></td>
            <td style="padding:10px;color:var(--sm-muted);font-size:12px"><?php echo esc_html( $tp['purpose'] ); ?></td>
            <td style="padding:10px"><a href="<?php echo esc_url( $tp['privacy'] ); ?>" target="_blank" rel="noopener" style="color:var(--sm-accent);font-size:12px">View →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Data Retention -->
  <div class="sm-card" style="margin-top:20px">
    <div class="sm-card-header"><span class="sm-card-title">Data Retention Policy</span></div>
    <div class="sm-card-body">
      <?php $ret_days = $s->capi_log_retention_days(); ?>
      <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:16px">
        <div class="sm-metric">
          <div class="sm-metric-label">Retention Period</div>
          <div class="sm-metric-value" style="font-size:22px"><?php echo esc_html( $ret_days ); ?>d</div>
        </div>
        <div class="sm-metric">
          <div class="sm-metric-label">Next Purge</div>
          <div class="sm-metric-value" style="font-size:14px"><?php
            $next = wp_next_scheduled('stellar_meta_enforce_retention');
            echo $next ? esc_html( human_time_diff( $next ) . ' from now' ) : esc_html__( 'Not yet scheduled', 'stellar-meta' );
          ?></div>
        </div>
      </div>
      <div class="sm-notice sm-notice-info" style="font-size:12px">
        Data purged automatically: Audit log, Event queue, Pixel dedup log.
        Change the retention period in <a href="<?php echo esc_url( admin_url('admin.php?page=stellar-meta-settings') ); ?>">Settings → CAPI Engine</a>.
      </div>
    </div>
  </div>

  <!-- GDPR Rights -->
  <div class="sm-card" style="margin-top:20px">
    <div class="sm-card-header"><span class="sm-card-title">User Rights (GDPR / CCPA)</span></div>
    <div class="sm-card-body">
      <div class="sm-grid-2">
        <div>
          <strong style="font-size:13px;display:block;margin-bottom:8px">WordPress privacy tools</strong>
          <ul style="list-style:disc;padding-left:18px;font-size:13px;color:var(--sm-muted);line-height:2.2">
            <li><a href="<?php echo esc_url( admin_url('export-personal-data.php') ); ?>" style="color:var(--sm-accent)">Export Personal Data</a></li>
            <li><a href="<?php echo esc_url( admin_url('tools.php?page=remove_personal_data') ); ?>" style="color:var(--sm-accent)">Erase Personal Data</a></li>
            <li><a href="<?php echo esc_url( admin_url('options-privacy.php') ); ?>" style="color:var(--sm-accent)">Privacy Policy Settings</a></li>
          </ul>
        </div>
        <div>
          <strong style="font-size:13px;display:block;margin-bottom:8px">Stellar Meta erases on request</strong>
          <ul style="list-style:disc;padding-left:18px;font-size:13px;color:var(--sm-muted);line-height:2.2">
            <li>LTV &amp; churn prediction scores</li>
            <li>Identity graph entries</li>
            <li>Touchpoint / attribution records</li>
            <li>Segment memberships</li>
            <li>Consent records &amp; audit entries</li>
          </ul>
        </div>
      </div>
    </div>
  </div>

</div>
