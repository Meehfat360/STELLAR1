<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) ); }

$anomalies = Stellar_Meta_Anomaly_Detector::get_recent(30);
$settings  = Stellar_Meta_Settings::instance();
$enabled   = $settings->is_anomaly_enabled();
?>
<div class="sm-header">
  <div class="sm-header-left">
    <div class="sm-logo">S</div>
    <div>
      <div class="sm-header-title">Anomaly Alerts</div>
      <div class="sm-header-sub">Real-time detection · ROAS drops · Pixel failures · Volume spikes</div>
    </div>
  </div>
  <div class="sm-header-right">
    <?php if ($enabled) : ?>
    <span class="sm-badge sm-badge-green"><span class="sm-dot"></span> Monitoring Active</span>
    <?php else : ?>
    <span class="sm-badge sm-badge-red"><span class="sm-dot"></span> Monitoring Disabled</span>
    <?php endif; ?>
    <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-settings#v2-alerts')); ?>" class="sm-btn sm-btn-secondary sm-btn-sm">Configure Alerts</a>
  </div>
</div>

<div class="sm-content">

  <?php if (!$enabled) : ?>
  <div class="sm-notice sm-notice-warning" style="display:flex;align-items:center;justify-content:space-between">
    <span>⚡ Anomaly detection is disabled. Enable it in Settings to receive ROAS drop and pixel failure alerts.</span>
    <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-settings#v2-alerts')); ?>" class="sm-btn sm-btn-primary sm-btn-sm">Enable →</a>
  </div>
  <?php endif; ?>

  <!-- Alert config summary -->
  <div class="sm-grid-3" style="margin-bottom:20px">
    <?php
    $email   = $settings->anomaly_alert_email() ?: get_option('admin_email');
    $slack   = $settings->anomaly_slack_webhook() ? '✓ Connected' : '✗ Not set';
    $drop    = $settings->anomaly_roas_drop_pct() . '%';
    $cards   = [
      ['Email Alerts', $email, 'var(--sm-blue)', 'Sends to this address on critical events'],
      ['Slack Webhook', $slack, $settings->anomaly_slack_webhook() ? 'var(--sm-green)' : 'var(--sm-red)', 'Instant Slack notifications'],
      ['ROAS Drop Threshold', $drop, 'var(--sm-amber)', 'Alert fires when ROAS drops by this %'],
    ];
    foreach ($cards as [$label, $val, $color, $desc]) : ?>
    <div class="sm-card">
      <div class="sm-card-body" style="padding:14px">
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--sm-muted);margin-bottom:6px;font-family:'DM Mono',monospace"><?php echo esc_html($label) ?></div>
        <div style="font-size:13px;font-weight:600;color:<?php echo $color ?>;word-break:break-all;margin-bottom:4px"><?php echo esc_html($val) ?></div>
        <div style="font-size:11px;color:var(--sm-muted)"><?php echo esc_html($desc) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Anomaly log -->
  <div class="sm-card">
    <div class="sm-card-header">
      <span class="sm-card-title">Anomaly Log — Last 30 Events</span>
      <span class="sm-badge sm-badge-muted">Auto-refreshes every 15 min</span>
    </div>
    <div class="sm-card-body" style="padding:0">
      <?php if (empty($anomalies)) : ?>
      <div style="padding:40px;text-align:center;color:var(--sm-muted)">
        <div style="font-size:28px;margin-bottom:10px">✓</div>
        <div style="font-size:14px;font-weight:600;margin-bottom:4px">No anomalies detected</div>
        <div style="font-size:12px">Everything is running normally. Check back here if you suspect an issue.</div>
      </div>
      <?php else : ?>
      <table class="sm-table">
        <thead>
          <tr>
            <th>Metric</th><th>Baseline</th><th>Observed</th><th>Deviation</th><th>Severity</th><th>Time</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($anomalies as $row) :
          $sev      = esc_attr($row['severity']);
          $dev_pct  = round((float)$row['deviation_pct'], 1);
          $is_drop  = (float)$row['observed_value'] < (float)$row['baseline_value'];
          $arrow    = $is_drop ? '▼' : '▲';
          $color    = $row['severity'] === 'critical' ? '#f87171' : ($row['severity'] === 'warning' ? 'var(--sm-amber)' : 'var(--sm-blue)');
        ?>
        <tr class="sm-anomaly-row-<?php echo $sev ?>">
          <td>
            <div style="font-weight:600;font-size:12px"><?php echo esc_html($row['metric']) ?></div>
            <?php if ($row['notes']) : ?><div style="font-size:10px;color:var(--sm-muted)"><?php echo esc_html($row['notes']) ?></div><?php endif; ?>
          </td>
          <td style="font-family:'DM Mono',monospace"><?php echo number_format((float)$row['baseline_value'], 2) ?></td>
          <td style="font-family:'DM Mono',monospace;color:<?php echo $color ?>"><?php echo number_format((float)$row['observed_value'], 2) ?></td>
          <td style="font-family:'DM Mono',monospace;color:<?php echo $color ?>"><?php echo $arrow . $dev_pct ?>%</td>
          <td><span class="sm-tier sm-sev-<?php echo $sev ?>" style="font-size:10px"><?php echo esc_html(ucfirst($sev)) ?></span></td>
          <td style="font-size:11px;color:var(--sm-muted)"><?php echo esc_html(wp_date('M j H:i', strtotime($row['created_at']))) ?></td>
          <td>
            <?php if ($row['alert_sent']) : ?>
            <span style="color:var(--sm-green);font-size:11px">✓ Alerted</span>
            <?php else : ?>
            <span style="color:var(--sm-muted);font-size:11px">Pending</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div>
