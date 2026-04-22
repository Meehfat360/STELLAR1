<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) ); }
$settings     = Stellar_Meta_Settings::instance();
$refresh_secs = $settings->live_refresh_seconds();
// Server-side initial load
$initial_metrics = Stellar_Meta_Live_Stats::get_live_metrics();
$initial_funnel  = Stellar_Meta_Live_Stats::get_live_funnel();
?>
<div class="sm-header">
  <div class="sm-header-left">
    <div class="sm-logo">S</div>
    <div>
      <div class="sm-header-title">Live Dashboard</div>
      <div class="sm-header-sub">Real-time event stream · Updates every <?php echo esc_html($refresh_secs); ?>s</div>
    </div>
  </div>
  <div class="sm-header-right">
    <span class="sm-badge sm-badge-green" id="sm-live-indicator"><span class="sm-dot sm-dot-pulse"></span> Live</span>
    <select class="sm-select" id="sm-refresh-interval" style="font-size:11px;padding:4px 10px;border-radius:6px;background:var(--sm-raised);border:1px solid var(--sm-border);color:var(--sm-text)">
      <option value="10" <?php selected($refresh_secs,10); ?>>10s</option>
      <option value="30" <?php selected($refresh_secs,30); ?>>30s</option>
      <option value="60" <?php selected($refresh_secs,60); ?>>60s</option>
    </select>
    <button class="sm-btn sm-btn-secondary sm-btn-sm" id="sm-pause-live">⏸ Pause</button>
  </div>
</div>

<div class="sm-content">

  <!-- Live KPI row -->
  <div class="sm-metrics" id="sm-live-kpis">
    <?php
    $kpis = [
      ['Events/min',   $initial_metrics['events_per_minute'],                  'var(--sm-blue)',   'sm-kpi-epm'],
      ['Revenue/hr',   '$'.number_format($initial_metrics['revenue_per_hour'],2),'var(--sm-gold2)','sm-kpi-revhr'],
      ['CAPI Match',   $initial_metrics['capi_match_rate'].'%',                'var(--sm-green)',  'sm-kpi-match'],
      ['ROAS Today',   $initial_metrics['roas_today'].'×',                     'var(--sm-purple)', 'sm-kpi-roas'],
    ];
    foreach($kpis as [$label,$val,$color,$id]): ?>
    <div class="sm-metric">
      <div class="sm-metric-label"><?php echo esc_html($label); ?></div>
      <div class="sm-metric-value" id="<?php echo $id; ?>" style="color:<?php echo $color; ?>"><?php echo esc_html($val); ?></div>
      <div class="sm-metric-delta flat" id="<?php echo $id; ?>-delta">●</div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if($initial_metrics['open_anomalies'] > 0): ?>
  <div class="sm-notice" style="background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.3);display:flex;justify-content:space-between;align-items:center" id="sm-anomaly-banner">
    🚨 <strong><?php echo $initial_metrics['open_anomalies']; ?> active alert<?php echo $initial_metrics['open_anomalies']>1?'s':''; ?></strong> detected.
    <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-alerts')); ?>" class="sm-btn sm-btn-sm" style="background:rgba(239,68,68,.2);color:#f87171;border-color:rgba(239,68,68,.3)">View →</a>
  </div>
  <?php endif; ?>

  <div class="sm-grid-2">

    <!-- Live Funnel -->
    <div class="sm-card">
      <div class="sm-card-header">
        <span class="sm-card-title">Live Funnel — 24h</span>
        <span class="sm-badge sm-badge-muted" id="sm-funnel-updated">Just now</span>
      </div>
      <div class="sm-card-body">
        <?php
        $steps = [
          ['ViewContent',      $initial_funnel['view_content'],      '#4A9EFF', 'sm-live-vc'],
          ['AddToCart',        $initial_funnel['add_to_cart'],       '#A78BFA', 'sm-live-ac'],
          ['InitiateCheckout', $initial_funnel['initiate_checkout'], '#F5A623', 'sm-live-ic'],
          ['Purchase',         $initial_funnel['purchase'],          '#3FD68A', 'sm-live-pu'],
        ];
        $max = max(array_column($steps, 1)) ?: 1;
        foreach($steps as [$label,$count,$color,$id]): $pct = $max>0?round($count/$max*100):0; ?>
        <div class="sm-funnel-row">
          <div class="sm-funnel-label"><?php echo esc_html($label); ?></div>
          <div class="sm-funnel-track">
            <div class="sm-funnel-bar" id="<?php echo $id;?>-bar" style="width:<?php echo $pct;?>%;background:<?php echo $color;?>22;color:<?php echo $color;?>"><?php echo number_format($count); ?></div>
          </div>
          <div class="sm-funnel-count" id="<?php echo $id;?>"><?php echo number_format($count); ?></div>
        </div>
        <?php endforeach; ?>
        <hr class="sm-sep">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
          <?php foreach([['Cart→','cart_rate','#A78BFA'],['Checkout→','checkout_rate','#F5A623'],['Close→','purchase_rate','#3FD68A']] as [$l,$k,$c]): ?>
          <div style="text-align:center;padding:10px;background:var(--sm-raised);border-radius:8px">
            <div style="font-size:17px;font-weight:700;font-family:'DM Mono',monospace;color:<?php echo $c?>" id="sm-rate-<?php echo $k?>"><?php echo $initial_funnel[$k]; ?>%</div>
            <div style="font-size:10px;color:var(--sm-muted);margin-top:2px"><?php echo esc_html($l); ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Live Activity Feed -->
    <div class="sm-card">
      <div class="sm-card-header">
        <span class="sm-card-title">Live Activity Feed</span>
        <span class="sm-badge sm-badge-green" id="sm-feed-status"><span class="sm-dot sm-dot-pulse"></span> Streaming</span>
      </div>
      <div class="sm-card-body" style="padding:0">
        <div id="sm-activity-feed" style="display:flex;flex-direction:column;max-height:340px;overflow-y:auto">
          <div style="padding:24px;text-align:center;color:var(--sm-muted);font-size:12px">Loading activity…</div>
        </div>
      </div>
    </div>

  </div><!-- /grid-2 -->

  <!-- Live Event Stream -->
  <div class="sm-card" style="margin-top:18px">
    <div class="sm-card-header">
      <span class="sm-card-title">Event Stream</span>
      <div style="display:flex;gap:8px;align-items:center">
        <select id="sm-event-filter" style="font-size:11px;padding:3px 8px;border-radius:5px;background:var(--sm-raised);border:1px solid var(--sm-border);color:var(--sm-text)">
          <option value="">All Events</option>
          <option value="Purchase">Purchase</option>
          <option value="AddToCart">AddToCart</option>
          <option value="ViewContent">ViewContent</option>
          <option value="InitiateCheckout">InitiateCheckout</option>
        </select>
        <select id="sm-platform-filter" style="font-size:11px;padding:3px 8px;border-radius:5px;background:var(--sm-raised);border:1px solid var(--sm-border);color:var(--sm-text)">
          <option value="">All Platforms</option>
          <option value="meta">Meta</option>
          <option value="google_ads">Google Ads</option>
        </select>
        <span style="font-size:10px;color:var(--sm-muted)" id="sm-stream-count"></span>
      </div>
    </div>
    <div class="sm-card-body" style="padding:0">
      <div id="sm-live-stream" style="max-height:400px;overflow-y:auto">
        <div style="padding:20px;text-align:center;color:var(--sm-muted);font-size:12px">Connecting to event stream…</div>
      </div>
    </div>
  </div>

  <!-- Platform Health Row -->
  <div class="sm-grid-3" style="margin-top:18px">
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">Queue Health</span></div>
      <div class="sm-card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <?php foreach([['Delivered','var(--sm-green)','sm-ql-delivered',$initial_metrics['delivered_24h']],['Failed','var(--sm-red)','sm-ql-failed',$initial_metrics['failed_24h']]] as [$l,$c,$id,$v]): ?>
          <div style="text-align:center;background:var(--sm-raised);border-radius:8px;padding:12px">
            <div style="font-size:22px;font-weight:700;font-family:'DM Mono',monospace;color:<?php echo $c?>" id="<?php echo $id?>"><?php echo number_format($v) ?></div>
            <div style="font-size:10px;color:var(--sm-muted);margin-top:3px;text-transform:uppercase;letter-spacing:.08em"><?php echo $l ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:12px;font-size:11px;color:var(--sm-muted);text-align:center" id="sm-avg-lat">Avg latency: <?php echo $initial_metrics['avg_latency_ms'] ?>ms</div>
      </div>
    </div>
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">Revenue / Hour</span></div>
      <div class="sm-card-body" style="padding:0 16px"><div style="height:80px"><canvas id="sm-rev-sparkline"></canvas></div></div>
    </div>
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">Events / Minute</span></div>
      <div class="sm-card-body" style="padding:0 16px"><div style="height:80px"><canvas id="sm-epm-sparkline"></canvas></div></div>
    </div>
  </div>

</div><!-- /sm-content -->

<style>
@keyframes sm-pulse { 0%,100%{opacity:1} 50%{opacity:.3} }
.sm-dot-pulse { animation: sm-pulse 1.5s ease-in-out infinite; }
.sm-activity-item { display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--sm-border);transition:background .2s; }
.sm-activity-item:last-child { border-bottom:none; }
.sm-activity-item:hover { background:var(--sm-raised); }
.sm-activity-icon { width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0; }
.sm-stream-row { display:flex;align-items:center;gap:8px;padding:7px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:11px;font-family:'DM Mono',monospace; }
.sm-stream-row:hover { background:var(--sm-raised); }
.sm-stream-dot { width:6px;height:6px;border-radius:50%;flex-shrink:0; }
.sm-stream-time { color:var(--sm-muted);min-width:52px;text-align:right; }
.sm-stream-latency { color:var(--sm-muted);min-width:44px;text-align:right; }
.sm-new-event { animation:sm-flash .6s ease-out; }
@keyframes sm-flash { 0%{background:rgba(201,168,76,.15)} 100%{background:transparent} }
</style>

<script>
(function($){
  var refreshSecs = <?php echo (int)$refresh_secs; ?>;
  var paused = false;
  var timer;
  var lastEventId = 0;
  var eventFilter = '';
  var platformFilter = '';

  // Sparkline data
  var revHistory = [], epmHistory = [];
  var revChart, epmChart;

  function initSparklines(){
    var opts = { responsive:true,maintainAspectRatio:false,
      plugins:{legend:{display:false}},
      scales:{x:{display:false},y:{display:false}},
      elements:{point:{radius:0}}
    };
    var labels = Array.from({length:10},function(_,i){return i+1+'';});
    revChart = new Chart(document.getElementById('sm-rev-sparkline'),{type:'line',data:{labels:labels,datasets:[{data:Array(10).fill(0),borderColor:'#C9A84C',backgroundColor:'rgba(201,168,76,.12)',tension:.4,fill:true,borderWidth:1.5}]},options:opts});
    epmChart = new Chart(document.getElementById('sm-epm-sparkline'),{type:'line',data:{labels:labels,datasets:[{data:Array(10).fill(0),borderColor:'#4A9EFF',backgroundColor:'rgba(74,158,255,.12)',tension:.4,fill:true,borderWidth:1.5}]},options:opts});
  }

  function updateSparkline(chart, val){
    var d = chart.data.datasets[0].data;
    d.push(val); if(d.length>10) d.shift();
    chart.update('none');
  }

  // Activity feed render
  function renderActivity(items){
    if(!items||!items.length){ $('#sm-activity-feed').html('<div style="padding:20px;text-align:center;color:var(--sm-muted);font-size:12px">No recent activity</div>'); return; }
    var html='';
    items.forEach(function(item){
      var isPurchase=(item.type==='purchase');
      var iconBg=isPurchase?'rgba(63,214,138,.15)':'rgba(167,139,250,.15)';
      var iconColor=isPurchase?'var(--sm-green)':'var(--sm-purple)';
      var icon=isPurchase?'💰':'🛒';
      html+='<div class="sm-activity-item">';
      html+='<div class="sm-activity-icon" style="background:'+iconBg+';color:'+iconColor+'">'+icon+'</div>';
      html+='<div style="flex:1;min-width:0">';
      html+='<div style="font-size:12px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">';
      if(isPurchase) html+=escHtml(item.name)+' purchased <strong>'+escHtml(item.product_name)+'</strong>';
      else html+='Visitor added <strong>'+escHtml(item.product_name)+'</strong> to cart';
      html+='</div>';
      html+='<div style="font-size:10px;color:var(--sm-muted);margin-top:2px">'+escHtml(item.time_ago)+'</div>';
      html+='</div>';
      if(item.total>0) html+='<div style="font-size:12px;font-weight:600;font-family:\'DM Mono\',monospace;color:var(--sm-gold2);white-space:nowrap">$'+item.total.toFixed(2)+'</div>';
      html+='</div>';
    });
    $('#sm-activity-feed').html(html);
  }

  // Event stream render
  function renderStream(events){
    if(!events||!events.length) return;
    var $stream=$('#sm-live-stream');
    var filter=eventFilter; var pfilt=platformFilter;
    var filtered=events.filter(function(e){
      return (!filter||e.event_name===filter)&&(!pfilt||e.platform===pfilt);
    });
    $('#sm-stream-count').text(filtered.length+' events');
    var html='';
    filtered.forEach(function(e,i){
      var isNew=(i<3);
      var dotColor={delivered:'var(--sm-green)',failed:'var(--sm-red)',retrying:'var(--sm-amber)',pending:'var(--sm-blue)',processing:'var(--sm-blue)'}[e.status]||'var(--sm-muted)';
      var platformBadge=e.platform==='google_ads'?'<span style="font-size:9px;padding:1px 5px;border-radius:4px;background:rgba(245,166,35,.15);color:var(--sm-amber);margin-left:4px">GAds</span>':'';
      html+='<div class="sm-stream-row'+(isNew?' sm-new-event':'')+'">';
      html+='<div class="sm-stream-dot" style="background:'+dotColor+'"></div>';
      html+='<div style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+escHtml(e.event_name)+platformBadge;
      if(e.product_name) html+=' <span style="color:var(--sm-muted)">— '+escHtml(e.product_name)+'</span>';
      html+='</div>';
      if(e.value) html+='<div style="color:var(--sm-gold2);min-width:60px;text-align:right">$'+parseFloat(e.value).toFixed(2)+'</div>';
      html+='<span style="font-size:9px;padding:1px 6px;border-radius:4px;background:rgba(255,255,255,.04)">'+escHtml(e.status)+'</span>';
      html+='<div class="sm-stream-latency">'+(e.latency_ms?e.latency_ms+'ms':'—')+'</div>';
      html+='<div class="sm-stream-time">'+escHtml(e.time_ago)+'</div>';
      html+='</div>';
    });
    $stream.html(html);
  }

  // Update KPI tiles
  function updateKPIs(metrics){
    $('#sm-kpi-epm').text(metrics.events_per_minute);
    $('#sm-kpi-revhr').text('$'+metrics.revenue_per_hour.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}));
    $('#sm-kpi-match').text(metrics.capi_match_rate+'%');
    $('#sm-kpi-roas').text(metrics.roas_today+'×');
    $('#sm-ql-delivered').text(metrics.delivered_24h.toLocaleString());
    $('#sm-ql-failed').text(metrics.failed_24h.toLocaleString());
    $('#sm-avg-lat').text('Avg latency: '+metrics.avg_latency_ms+'ms');
    updateSparkline(revChart, metrics.revenue_per_hour);
    updateSparkline(epmChart, metrics.events_per_minute);
  }

  // Update live funnel
  function updateFunnel(f){
    var max=Math.max(f.view_content,f.add_to_cart,f.initiate_checkout,f.purchase)||1;
    [['vc',f.view_content],['ac',f.add_to_cart],['ic',f.initiate_checkout],['pu',f.purchase]].forEach(function(p){
      var id='sm-live-'+p[0]; var val=p[1];
      $('#'+id).text(val.toLocaleString());
      $('#'+id+'-bar').css('width',Math.round(val/max*100)+'%').text(val.toLocaleString());
    });
    $('#sm-rate-cart_rate').text(f.cart_rate+'%');
    $('#sm-rate-checkout_rate').text(f.checkout_rate+'%');
    $('#sm-rate-purchase_rate').text(f.purchase_rate+'%');
    var now=new Date();
    $('#sm-funnel-updated').text('Updated '+now.toLocaleTimeString());
  }

  // Main poll
  function doPoll(){
    if(paused) return;
    $.post(StellarMetaAdmin.ajaxUrl,{action:'stellar_meta_live_poll',nonce:StellarMetaAdmin.nonce},function(res){
      if(!res.success) return;
      var d=res.data;
      updateKPIs(d.metrics);
      updateFunnel(d.funnel);
      renderActivity(d.activity);
      renderStream(d.events);
    });
  }

  function startTimer(){
    clearInterval(timer);
    timer=setInterval(doPoll, refreshSecs*1000);
  }

  // Init
  initSparklines();
  doPoll();
  startTimer();

  // Controls
  $('#sm-pause-live').on('click',function(){
    paused=!paused;
    if(paused){$(this).text('▶ Resume');$('#sm-live-indicator').html('<span class="sm-dot" style="background:var(--sm-amber)"></span> Paused');}
    else{$(this).text('⏸ Pause');$('#sm-live-indicator').html('<span class="sm-dot sm-dot-pulse"></span> Live');doPoll();}
  });
  $('#sm-refresh-interval').on('change',function(){
    refreshSecs=parseInt($(this).val());
    startTimer();
  });
  $('#sm-event-filter,#sm-platform-filter').on('change',function(){
    eventFilter=$('#sm-event-filter').val();
    platformFilter=$('#sm-platform-filter').val();
    doPoll();
  });

  function escHtml(s){
    if(!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
}(jQuery));
</script>
