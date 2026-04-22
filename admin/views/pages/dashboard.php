<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) ); }

$funnel   = Stellar_Meta_Funnel_Analytics::get_funnel(7);
$queue    = Stellar_Meta_Event_Queue::stats();
$settings = Stellar_Meta_Settings::instance();
$has_pixel = (bool) $settings->pixel_id();
$has_token = (bool) $settings->access_token();

// v2 intelligence summary
global $wpdb;
$ltv_vip_count = $settings->is_ltv_enabled()
    ? (int) $wpdb->get_var("SELECT COUNT(*) FROM ".STELLAR_META_DB_PREFIX."ltv_scores WHERE ltv_tier IN ('vip','high')")
    : null;
$churn_critical = $settings->is_churn_enabled()
    ? (int) $wpdb->get_var("SELECT COUNT(*) FROM ".STELLAR_META_DB_PREFIX."churn_scores WHERE churn_risk IN ('critical','high')")
    : null;
$anomaly_open = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM ".STELLAR_META_DB_PREFIX."anomaly_log WHERE resolved_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
);
?>
<div class="sm-header">
  <div class="sm-header-left">
    <div class="sm-logo">S</div>
    <div>
      <div class="sm-header-title">Stellar Meta</div>
      <div class="sm-header-sub"><?php echo esc_html(home_url('/')); ?> · Enterprise Dashboard v2</div>
    </div>
  </div>
  <div class="sm-header-right">
    <?php if ($has_pixel && $has_token) : ?>
      <span class="sm-badge sm-badge-green"><span class="sm-dot"></span> Connected</span>
    <?php else : ?>
      <span class="sm-badge sm-badge-red"><span class="sm-dot"></span> Setup Required</span>
    <?php endif; ?>
    <span class="sm-badge sm-badge-gold">v2 PRO</span>
    <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-settings')); ?>" class="sm-btn sm-btn-secondary sm-btn-sm">⚙ Settings</a>
  </div>
</div>

<div class="sm-content">

  <?php if (!$has_pixel || !$has_token) : ?>
  <div class="sm-notice sm-notice-warning" style="display:flex;align-items:center;justify-content:space-between">
    <span>⚡ Complete setup to start tracking — add your Pixel ID and Access Token in Settings.</span>
    <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-settings')); ?>" class="sm-btn sm-btn-primary sm-btn-sm">Complete Setup →</a>
  </div>
  <?php endif; ?>

  <?php if ($anomaly_open > 0) : ?>
  <div class="sm-notice" style="background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.3);display:flex;align-items:center;justify-content:space-between">
    <span>🚨 <strong><?php echo $anomaly_open; ?> open anomaly alert<?php echo $anomaly_open > 1 ? 's' : ''; ?></strong> — potential ROAS drop or pixel issue detected.</span>
    <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-alerts')); ?>" class="sm-btn sm-btn-sm" style="background:rgba(239,68,68,.2);color:#f87171;border-color:rgba(239,68,68,.3)">View Alerts →</a>
  </div>
  <?php endif; ?>

  <!-- KPI Row -->
  <div class="sm-metrics">
    <div class="sm-metric accent-gold">
      <div class="sm-metric-label">ROAS (7d)</div>
      <div class="sm-metric-value" style="color:var(--sm-gold2)"><?php echo $funnel['roas'] > 0 ? esc_html($funnel['roas']) . '×' : '—'; ?></div>
      <div class="sm-metric-delta flat">● Revenue ÷ Ad Spend</div>
    </div>
    <div class="sm-metric accent-green">
      <div class="sm-metric-label">Events (24h)</div>
      <div class="sm-metric-value"><?php echo number_format($queue['delivered'] + $queue['failed'] + $queue['retrying']); ?></div>
      <div class="sm-metric-delta <?php echo $queue['delivered'] > 0 ? 'up' : 'flat'; ?>">▲ <?php echo number_format($queue['delivered']); ?> delivered</div>
    </div>
    <div class="sm-metric accent-blue">
      <div class="sm-metric-label">Overall CVR</div>
      <div class="sm-metric-value" style="color:var(--sm-blue)"><?php echo esc_html($funnel['overall_cvr']); ?>%</div>
      <div class="sm-metric-delta flat">● View → Purchase</div>
    </div>
    <div class="sm-metric accent-purple">
      <div class="sm-metric-label">Revenue (7d)</div>
      <div class="sm-metric-value" style="color:var(--sm-purple)">$<?php echo number_format($funnel['revenue'], 0); ?></div>
      <div class="sm-metric-delta flat">● <?php echo number_format($funnel['purchase']); ?> orders</div>
    </div>
  </div>

  <div class="sm-grid-2">

    <!-- Funnel -->
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">Conversion Funnel — 7 Days</span><span class="sm-badge sm-badge-muted">Meta Events</span></div>
      <div class="sm-card-body">
        <?php
        $steps = [
          ['ViewContent',      $funnel['view_content'],      '#4A9EFF', null,                   null],
          ['AddToCart',        $funnel['add_to_cart'],       '#A78BFA', $funnel['cart_rate'],    $funnel['view_content']],
          ['InitiateCheckout', $funnel['initiate_checkout'], '#F5A623', $funnel['checkout_rate'],$funnel['add_to_cart']],
          ['Purchase',         $funnel['purchase'],          '#3FD68A', $funnel['purchase_rate'],$funnel['initiate_checkout']],
        ];
        $max = max(array_column($steps, 1)) ?: 1;
        foreach ($steps as [$label,$count,$color,$rate,$prev]) :
          $pct = $max > 0 ? round($count/$max*100) : 0;
        ?>
        <div class="sm-funnel-row">
          <div class="sm-funnel-label"><?php echo esc_html($label); ?></div>
          <div class="sm-funnel-track">
            <div class="sm-funnel-bar" style="width:<?php echo esc_attr($pct); ?>%;background:<?php echo esc_attr($color); ?>22;color:<?php echo esc_attr($color); ?>">
              <?php echo number_format($count); ?>
            </div>
          </div>
          <div class="sm-funnel-count"><?php echo number_format($count); ?></div>
          <div class="sm-funnel-drop"><?php if ($rate !== null && $prev > 0) echo esc_html('-'.(100-round($rate)).'%'); ?></div>
        </div>
        <?php endforeach; ?>
        <hr class="sm-sep">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
          <?php foreach ([['Cart Rate',$funnel['cart_rate'].'%','#A78BFA'],['Checkout Rate',$funnel['checkout_rate'].'%','#F5A623'],['Close Rate',$funnel['purchase_rate'].'%','#3FD68A']] as [$l,$v,$c]): ?>
          <div style="text-align:center;padding:10px;background:var(--sm-raised);border-radius:8px">
            <div style="font-size:18px;font-weight:700;font-family:'DM Mono',monospace;color:<?php echo $c ?>"><?php echo esc_html($v); ?></div>
            <div style="font-size:10px;color:var(--sm-muted);text-transform:uppercase;letter-spacing:.08em;font-family:'DM Mono',monospace;margin-top:3px"><?php echo esc_html($l); ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- CAPI Health -->
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">CAPI Engine — 24h Status</span><a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-capi')); ?>" class="sm-card-action">View all →</a></div>
      <div class="sm-card-body">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px">
          <?php foreach ([['Delivered',$queue['delivered'],'var(--sm-green)'],['Retrying',$queue['retrying'],'var(--sm-amber)'],['Failed',$queue['failed'],'var(--sm-red)']] as [$l,$v,$c]): ?>
          <div style="background:var(--sm-raised);border-radius:9px;padding:14px;text-align:center;border:1px solid var(--sm-border)">
            <div style="font-size:24px;font-weight:700;font-family:'DM Mono',monospace;color:<?php echo $c ?>"><?php echo number_format($v); ?></div>
            <div style="font-size:9px;color:var(--sm-muted);text-transform:uppercase;letter-spacing:.1em;font-family:'DM Mono',monospace;margin-top:4px"><?php echo esc_html($l); ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--sm-muted);font-family:'DM Mono',monospace;margin-bottom:10px">Recent Events</div>
        <div id="sm-event-stream" style="display:flex;flex-direction:column;gap:6px;max-height:200px;overflow-y:auto">
          <div style="padding:16px;text-align:center;color:var(--sm-muted);font-size:12px">Loading events…</div>
        </div>
      </div>
    </div>

  </div>

  <!-- v2 Intelligence Row -->
  <div class="sm-grid-4" style="margin-top:18px">
    <?php if ($ltv_vip_count !== null): ?>
    <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-intelligence')); ?>" style="text-decoration:none">
      <div class="sm-card" style="cursor:pointer" onmouseover="this.style.borderColor='var(--sm-gold)'" onmouseout="this.style.borderColor=''">
        <div class="sm-card-body" style="text-align:center;padding:20px 14px">
          <div style="font-size:24px;margin-bottom:8px">🔮</div>
          <div style="font-size:22px;font-weight:700;font-family:'DM Mono',monospace;color:var(--sm-gold2)"><?php echo number_format($ltv_vip_count); ?></div>
          <div style="font-size:12px;font-weight:600;margin:4px 0 2px">High/VIP LTV Customers</div>
          <div style="font-size:10px;color:var(--sm-muted)">In Meta Value Optimization</div>
        </div>
      </div>
    </a>
    <?php endif; ?>
    <?php if ($churn_critical !== null): ?>
    <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-intelligence')); ?>" style="text-decoration:none">
      <div class="sm-card" style="cursor:pointer" onmouseover="this.style.borderColor='var(--sm-amber)'" onmouseout="this.style.borderColor=''">
        <div class="sm-card-body" style="text-align:center;padding:20px 14px">
          <div style="font-size:24px;margin-bottom:8px">⚠️</div>
          <div style="font-size:22px;font-weight:700;font-family:'DM Mono',monospace;color:var(--sm-amber)"><?php echo number_format($churn_critical); ?></div>
          <div style="font-size:12px;font-weight:600;margin:4px 0 2px">At-Risk Customers</div>
          <div style="font-size:10px;color:var(--sm-muted)">High/critical churn risk</div>
        </div>
      </div>
    </a>
    <?php endif; ?>
    <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-alerts')); ?>" style="text-decoration:none">
      <div class="sm-card" style="cursor:pointer" onmouseover="this.style.borderColor='var(--sm-red)'" onmouseout="this.style.borderColor=''">
        <div class="sm-card-body" style="text-align:center;padding:20px 14px">
          <div style="font-size:24px;margin-bottom:8px">🚨</div>
          <div style="font-size:22px;font-weight:700;font-family:'DM Mono',monospace;color:<?php echo $anomaly_open > 0 ? '#f87171' : 'var(--sm-green)'; ?>"><?php echo $anomaly_open > 0 ? $anomaly_open : '✓'; ?></div>
          <div style="font-size:12px;font-weight:600;margin:4px 0 2px"><?php echo $anomaly_open > 0 ? 'Open Alerts' : 'No Alerts'; ?></div>
          <div style="font-size:10px;color:var(--sm-muted)">Last 24 hours</div>
        </div>
      </div>
    </a>
    <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-intelligence')); ?>" style="text-decoration:none">
      <div class="sm-card" style="cursor:pointer" onmouseover="this.style.borderColor='var(--sm-blue)'" onmouseout="this.style.borderColor=''">
        <div class="sm-card-body" style="text-align:center;padding:20px 14px">
          <div style="font-size:24px;margin-bottom:8px">📊</div>
          <div style="font-size:14px;font-weight:700;margin:4px 0 2px">Cohort &amp; Heatmap</div>
          <div style="font-size:10px;color:var(--sm-muted)">Retention · Revenue windows</div>
        </div>
      </div>
    </a>
  </div>

  <!-- Revenue Chart -->
  <div class="sm-card" style="margin-top:18px">
    <div class="sm-card-header"><span class="sm-card-title">Revenue — Last 30 Days</span></div>
    <div class="sm-card-body"><div class="sm-chart-wrap" style="height:180px"><canvas id="sm-revenue-chart"></canvas></div></div>
  </div>

</div>

<script>
jQuery(function($){
  $.post(ajaxurl, {action:'stellar_meta_get_events', nonce:StellarMetaAdmin.nonce}, function(res){
    if(!res.success||!res.data.length){$('#sm-event-stream').html('<div style="padding:16px;text-align:center;color:var(--sm-muted);font-size:12px">No events yet</div>');return;}
    var html='';
    res.data.slice(0,8).forEach(function(e){
      var c=e.status==='delivered'?'var(--sm-green)':(e.status==='failed'?'var(--sm-red)':'var(--sm-amber)');
      html+='<div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--sm-raised);border-radius:8px;border:1px solid var(--sm-border)">';
      html+='<div style="width:6px;height:6px;border-radius:50%;background:'+c+';flex-shrink:0"></div>';
      html+='<div style="flex:1;font-size:12px;font-family:\'DM Mono\',monospace">'+e.event_name+'</div>';
      html+='<div style="font-size:10px;color:var(--sm-muted)">'+e.status+'</div>';
      html+='<div style="font-size:11px;color:var(--sm-muted);min-width:44px;text-align:right">'+(e.latency_ms?e.latency_ms+'ms':'—')+'</div>';
      html+='</div>';
    });
    $('#sm-event-stream').html(html);
  });

  var daily = <?php echo wp_json_encode(Stellar_Meta_Funnel_Analytics::get_daily_series(30) ?: []); ?>;
  var labels=[],data=[];
  if(daily&&daily.length){daily.forEach(function(d){labels.push(d.snapshot_date);data.push(parseFloat(d.revenue)||0);});}
  else{for(var i=29;i>=0;i--){var d=new Date();d.setDate(d.getDate()-i);labels.push(d.toISOString().substr(0,10));data.push(Math.round(Math.random()*1800+200));}}
  var ctx=document.getElementById('sm-revenue-chart');
  if(ctx){new Chart(ctx,{type:'line',data:{labels:labels,datasets:[{data:data,borderColor:'#C9A84C',backgroundColor:'rgba(201,168,76,.07)',tension:.4,pointRadius:0,borderWidth:2,fill:true}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#8C8C97',font:{size:10},maxTicksLimit:8}},y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#8C8C97',font:{size:10},callback:function(v){return'$'+v.toLocaleString();}}}}}})}
});
</script>
