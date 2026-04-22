<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) ); }

$settings      = Stellar_Meta_Settings::instance();
$ltv_enabled   = $settings->is_ltv_enabled();
$churn_enabled = $settings->is_churn_enabled();
$churn_summary = $churn_enabled ? Stellar_Meta_Churn_Predictor::get_summary() : [];
$at_risk       = $churn_enabled ? Stellar_Meta_Churn_Predictor::get_at_risk(15) : [];

global $wpdb;
$ltv_tiers = $ltv_enabled ? (array) $wpdb->get_results(
    "SELECT ltv_tier, COUNT(*) AS cnt, AVG(predicted_12m) AS avg_12m, SUM(predicted_12m) AS total_12m
     FROM " . STELLAR_META_DB_PREFIX . "ltv_scores
     GROUP BY ltv_tier ORDER BY avg_12m DESC", ARRAY_A
) : [];

$heatmap_grid = $settings->is_heatmap_enabled() ? Stellar_Meta_Revenue_Heatmap::get_grid() : [];
$cohort_table = $settings->is_cohort_enabled() ? Stellar_Meta_Cohort_Analytics::get_cohort_table(6) : [];

$days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$tier_colors = ['vip'=>'sm-tier-vip','high'=>'sm-tier-high','mid'=>'sm-tier-mid','low'=>'sm-tier-low'];
$risk_colors = ['critical'=>'sm-risk-critical','high'=>'sm-risk-high','medium'=>'sm-risk-medium','low'=>'sm-risk-low'];
?>
<div class="sm-header">
  <div class="sm-header-left">
    <div class="sm-logo">S</div>
    <div>
      <div class="sm-header-title">Intelligence</div>
      <div class="sm-header-sub">LTV Prediction · Churn Risk · Revenue Heatmap · Cohort Analytics</div>
    </div>
  </div>
  <div class="sm-header-right">
    <button class="sm-btn sm-btn-secondary sm-btn-sm" id="sm-run-ltv">Run LTV Scoring</button>
    <button class="sm-btn sm-btn-secondary sm-btn-sm" id="sm-run-churn">Score Churn Risk</button>
    <button class="sm-btn sm-btn-secondary sm-btn-sm" id="sm-rebuild-heatmap">Rebuild Heatmap</button>
  </div>
</div>

<div class="sm-content">

  <?php if ( ! $ltv_enabled && ! $churn_enabled ) : ?>
  <div class="sm-notice sm-notice-warning">
    ⚡ LTV Prediction and Churn Prediction are disabled. Enable them in
    <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-settings#v2-ai')); ?>">Settings → AI Intelligence</a>.
    An OpenAI API key is required for AI-powered predictions.
  </div>
  <?php endif; ?>

  <?php if ( $ltv_enabled ) : ?>
  <!-- LTV Tier Distribution -->
  <div class="sm-section-title">Customer LTV Tiers</div>
  <div class="sm-grid-4" style="margin-bottom:20px">
    <?php
    $tier_defs = [
      ['vip',  'VIP',  'var(--sm-gold2)',   '>$2,000 predicted LTV'],
      ['high', 'High', 'var(--sm-blue)',    '$500–$2,000 predicted LTV'],
      ['mid',  'Mid',  'var(--sm-purple)',  '$100–$500 predicted LTV'],
      ['low',  'Low',  'var(--sm-muted)',   'Under $100 predicted LTV'],
    ];
    $tiers_by_key = [];
    foreach ($ltv_tiers as $t) $tiers_by_key[$t['ltv_tier']] = $t;
    foreach ($tier_defs as [$key,$label,$color,$desc]) :
      $cnt    = (int) ($tiers_by_key[$key]['cnt'] ?? 0);
      $avg12m = number_format((float)($tiers_by_key[$key]['avg_12m'] ?? 0), 0);
    ?>
    <div class="sm-card">
      <div class="sm-card-body" style="text-align:center;padding:18px 14px">
        <span class="sm-tier sm-tier-<?php echo esc_attr($key) ?>"><?php echo esc_html($label) ?></span>
        <div style="font-size:26px;font-weight:700;font-family:'DM Mono',monospace;color:<?php echo $color ?>;margin:10px 0 4px"><?php echo number_format($cnt) ?></div>
        <div style="font-size:11px;color:var(--sm-muted)">customers</div>
        <div style="font-size:12px;color:var(--sm-text);margin-top:8px">avg $<?php echo esc_html($avg12m) ?>/yr</div>
        <div style="font-size:10px;color:var(--sm-muted);margin-top:3px"><?php echo esc_html($desc) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ( $churn_enabled ) : ?>
  <div class="sm-grid-2" style="margin-bottom:20px">

    <!-- Churn Risk Summary -->
    <div class="sm-card">
      <div class="sm-card-header">
        <span class="sm-card-title">Churn Risk Distribution</span>
        <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-audiences')); ?>" class="sm-card-action">View win-back →</a>
      </div>
      <div class="sm-card-body">
        <?php
        $risk_defs = [
          ['critical','Critical','#f87171', 'Churn imminent — act now'],
          ['high',    'High',    'var(--sm-amber)', 'At-risk customers'],
          ['medium',  'Medium',  'var(--sm-blue)',  'Monitor closely'],
          ['low',     'Low',     'var(--sm-green)', 'Healthy'],
        ];
        $total_scored = array_sum($churn_summary);
        foreach ($risk_defs as [$key,$label,$color,$desc]) :
          $cnt = (int)($churn_summary[$key] ?? 0);
          $pct = $total_scored > 0 ? round($cnt/$total_scored*100) : 0;
        ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
            <div style="display:flex;align-items:center;gap:8px">
              <span class="sm-tier sm-risk-<?php echo esc_attr($key) ?>"><?php echo esc_html($label) ?></span>
              <span style="font-size:11px;color:var(--sm-muted)"><?php echo esc_html($desc) ?></span>
            </div>
            <span style="font-size:13px;font-weight:700;font-family:'DM Mono',monospace;color:<?php echo $color ?>"><?php echo number_format($cnt) ?></span>
          </div>
          <div class="sm-intel-bar-wrap">
            <div class="sm-intel-bar" style="width:<?php echo esc_attr($pct) ?>%;background:<?php echo $color ?>"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- At-Risk Customers -->
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">At-Risk Customers (Top 15)</span></div>
      <div class="sm-card-body" style="padding:0">
        <?php if ( empty($at_risk) ) : ?>
        <div style="padding:24px;text-align:center;color:var(--sm-muted);font-size:12px">No high-risk customers found. Run churn scoring to populate.</div>
        <?php else : ?>
        <table class="sm-table">
          <thead><tr><th>Customer</th><th>Risk</th><th>Days silent</th><th>Total spend</th></tr></thead>
          <tbody>
          <?php foreach ($at_risk as $c) :
            $risk = esc_html($c['churn_risk']);
            $email = esc_html($c['user_email'] ?? '—');
            $name  = esc_html($c['display_name'] ?? 'Guest');
          ?>
          <tr>
            <td>
              <div style="font-weight:600;font-size:12px"><?php echo $name ?></div>
              <div style="font-size:10px;color:var(--sm-muted)"><?php echo $email ?></div>
            </td>
            <td><span class="sm-tier sm-risk-<?php echo esc_attr($c['churn_risk']) ?>"><?php echo $risk ?></span></td>
            <td style="font-family:'DM Mono',monospace"><?php echo (int)$c['rfm_recency'] ?>d</td>
            <td style="font-family:'DM Mono',monospace">$<?php echo number_format((float)$c['rfm_monetary'],0) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Revenue Heatmap -->
  <?php if ( $settings->is_heatmap_enabled() ) : ?>
  <div class="sm-card" style="margin-bottom:20px">
    <div class="sm-card-header">
      <span class="sm-card-title">Revenue Heatmap — Best Ad Scheduling Windows</span>
      <span class="sm-badge sm-badge-muted">Last 90 days · darker = more revenue</span>
    </div>
    <div class="sm-card-body">
      <?php if ( empty($heatmap_grid) ) : ?>
        <div style="padding:24px;text-align:center;color:var(--sm-muted);font-size:12px">No heatmap data yet. Click "Rebuild Heatmap" above or wait for the daily cron.</div>
      <?php else :
        // Calculate max revenue for colour scaling
        $max_rev = 0;
        foreach ($heatmap_grid as $d_data) foreach ($d_data as $cell) if ((float)$cell['revenue'] > $max_rev) $max_rev = (float)$cell['revenue'];
      ?>
      <div class="sm-heatmap-wrap">
        <table class="sm-heatmap">
          <thead>
            <tr>
              <th style="min-width:36px"></th>
              <?php for($h=0;$h<24;$h++) echo '<th>' . sprintf('%02d',$h) . '</th>'; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($days as $di => $dname) : ?>
          <tr>
            <td class="sm-dow-label"><?php echo esc_html($dname) ?></td>
            <?php for($h=0;$h<24;$h++) :
              $cell = $heatmap_grid[$di][$h] ?? ['revenue'=>0,'orders'=>0];
              $rev  = (float)$cell['revenue'];
              $pct  = $max_rev > 0 ? $rev/$max_rev : 0;
              $cls  = $pct >= .8 ? 'sm-heat-5' : ($pct >= .6 ? 'sm-heat-4' : ($pct >= .4 ? 'sm-heat-3' : ($pct >= .2 ? 'sm-heat-2' : ($pct >= .05 ? 'sm-heat-1' : 'sm-heat-0'))));
              $title = '$' . number_format($rev,0) . ' · ' . (int)$cell['orders'] . ' orders';
            ?>
            <td class="<?php echo esc_attr($cls) ?>" title="<?php echo esc_attr($title) ?>">
              <?php if ((int)$cell['orders'] > 0) echo (int)$cell['orders']; ?>
            </td>
            <?php endfor; ?>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="display:flex;align-items:center;gap:6px;margin-top:10px;font-size:10px;color:var(--sm-muted)">
        <span>Low</span>
        <?php foreach(['sm-heat-0','sm-heat-1','sm-heat-2','sm-heat-3','sm-heat-4','sm-heat-5'] as $cls) : ?>
        <div class="<?php echo $cls ?>" style="width:16px;height:16px;border-radius:3px;display:inline-block"></div>
        <?php endforeach; ?>
        <span>High</span>
        <span style="margin-left:12px">Numbers = order count per cell</span>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Cohort Retention Table -->
  <?php if ( $settings->is_cohort_enabled() && ! empty($cohort_table) ) : ?>
  <div class="sm-card" style="margin-bottom:20px">
    <div class="sm-card-header">
      <span class="sm-card-title">Cohort Retention — Last 6 Months</span>
      <button class="sm-btn sm-btn-secondary sm-btn-sm" id="sm-rebuild-cohorts">Rebuild Cohorts</button>
    </div>
    <div class="sm-card-body">
      <div class="sm-cohort-wrap">
        <table class="sm-cohort-table">
          <thead>
            <tr>
              <th style="text-align:left">Cohort</th>
              <?php for($o=0;$o<=5;$o++) echo '<th>' . ($o===0 ? 'M+0' : "M+{$o}") . '</th>'; ?>
              <th>6-mo revenue</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($cohort_table as $month => $periods) :
            $label    = date('M Y', strtotime($month));
            $acquired = (int)($periods[0]['customers'] ?? 0);
            $rev6m    = array_sum(array_column($periods, 'revenue'));
          ?>
          <tr>
            <td><strong><?php echo esc_html($label) ?></strong><br><span style="font-size:10px;color:var(--sm-muted)"><?php echo number_format($acquired) ?> acquired</span></td>
            <?php for($o=0;$o<=5;$o++) :
              $p   = $periods[$o] ?? null;
              $ret = $p ? (float)$p['retention_pct'] : null;
              $cls = $ret === null ? 'sm-ret-0' : ($ret >= 75 ? 'sm-ret-100' : ($ret >= 50 ? 'sm-ret-75' : ($ret >= 25 ? 'sm-ret-50' : ($ret > 0 ? 'sm-ret-25' : 'sm-ret-0'))));
            ?>
            <td class="<?php echo $cls ?>">
              <?php echo $ret !== null ? round($ret) . '%' : '—' ?>
            </td>
            <?php endfor; ?>
            <td style="font-family:'DM Mono',monospace;color:var(--sm-gold2)">$<?php echo number_format($rev6m,0) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="font-size:11px;color:var(--sm-muted);margin-top:10px">% of acquisition cohort that placed another order in each subsequent month.</div>
    </div>
  </div>
  <?php elseif ($settings->is_cohort_enabled()) : ?>
  <div class="sm-card" style="margin-bottom:20px">
    <div class="sm-card-header"><span class="sm-card-title">Cohort Analytics</span></div>
    <div class="sm-card-body"><div style="padding:24px;text-align:center;color:var(--sm-muted);font-size:12px">No cohort data yet. Click "Rebuild Cohorts" or wait for the daily cron.</div>
    <div style="text-align:center;margin-top:8px"><button class="sm-btn sm-btn-primary sm-btn-sm" id="sm-rebuild-cohorts">Rebuild Cohorts Now</button></div></div>
  </div>
  <?php endif; ?>

</div><!-- /sm-content -->

<script>
jQuery(function($){
  $('#sm-run-ltv').on('click', function(){
    $(this).text('Scoring…').prop('disabled',true);
    $.post(ajaxurl, {action:'stellar_meta_run_ltv_batch', nonce:StellarMetaAdmin.nonce}, function(r){
      if(r.success) location.reload();
    });
  });
  $('#sm-run-churn').on('click', function(){
    $(this).text('Scoring…').prop('disabled',true);
    $.post(ajaxurl, {action:'stellar_meta_run_churn_batch', nonce:StellarMetaAdmin.nonce}, function(r){
      if(r.success) location.reload();
    });
  });
  $('#sm-rebuild-heatmap, [id="sm-rebuild-heatmap"]').on('click', function(){
    $(this).text('Rebuilding…').prop('disabled',true);
    $.post(ajaxurl, {action:'stellar_meta_rebuild_heatmap', nonce:StellarMetaAdmin.nonce}, function(r){
      if(r.success) location.reload();
    });
  });
  $('#sm-rebuild-cohorts').on('click', function(){
    $(this).text('Rebuilding…').prop('disabled',true);
    $.post(ajaxurl, {action:'stellar_meta_rebuild_cohorts', nonce:StellarMetaAdmin.nonce}, function(r){
      if(r.success) location.reload();
    });
  });
});
</script>
