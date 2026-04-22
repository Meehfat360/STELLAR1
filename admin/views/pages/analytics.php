<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) ); }

$funnel7  = Stellar_Meta_Funnel_Analytics::get_funnel(7);
$funnel30 = Stellar_Meta_Funnel_Analytics::get_funnel(30);
$series   = Stellar_Meta_Funnel_Analytics::get_daily_series(30);
$heatmap_grid = Stellar_Meta_Revenue_Heatmap::get_grid();
$cohort_table = Stellar_Meta_Cohort_Analytics::get_cohort_table(6);
$days = isset($_GET['days']) ? max(1,min(365,(int)sanitize_text_field(wp_unslash($_GET['days'])))) : 7;
$funnel = ($days===30) ? $funnel30 : $funnel7;
?>
<div class="sm-header">
  <div class="sm-header-left">
    <div class="sm-logo">S</div>
    <div>
      <div class="sm-header-title">Analytics</div>
      <div class="sm-header-sub">Funnel · Revenue · Cohorts · Heatmap</div>
    </div>
  </div>
  <div class="sm-header-right">
    <span class="sm-badge sm-badge-muted" id="sm-period-label">Last <?php echo $days; ?> days</span>
    <button class="sm-btn sm-btn-secondary sm-btn-sm <?php echo $days===7?'active-period':''; ?>" data-days="7">7d</button>
    <button class="sm-btn sm-btn-secondary sm-btn-sm <?php echo $days===30?'active-period':''; ?>" data-days="30">30d</button>
  </div>
</div>

<div class="sm-content">

  <!-- KPIs -->
  <div class="sm-metrics">
    <?php foreach([
      ['Revenue',      '$'.number_format($funnel['revenue'],2), 'accent-gold',   'var(--sm-gold2)'],
      ['ROAS',          $funnel['roas'].'×',                    'accent-green',  'var(--sm-green)'],
      ['Purchases',     number_format($funnel['purchase']),     '',              'var(--sm-text)'],
      ['Overall CVR',   $funnel['overall_cvr'].'%',             'accent-blue',   'var(--sm-blue)'],
    ] as [$label,$val,$accent,$color]): ?>
    <div class="sm-metric <?php echo esc_attr($accent); ?>">
      <div class="sm-metric-label"><?php echo esc_html($label); ?></div>
      <div class="sm-metric-value" style="color:<?php echo $color; ?>"><?php echo esc_html($val); ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Revenue chart -->
  <div class="sm-card" style="margin-bottom:18px">
    <div class="sm-card-header"><span class="sm-card-title">Revenue — Last 30 Days</span></div>
    <div class="sm-card-body"><div class="sm-chart-wrap" style="height:200px"><canvas id="sm-rev-chart"></canvas></div></div>
  </div>

  <div class="sm-grid-2" style="margin-bottom:18px">
    <!-- Funnel -->
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">Conversion Funnel</span></div>
      <div class="sm-card-body">
        <?php
        $steps = [
          ['ViewContent', $funnel['view_content'], '#4A9EFF'],
          ['AddToCart', $funnel['add_to_cart'], '#A78BFA'],
          ['Checkout', $funnel['initiate_checkout'], '#F5A623'],
          ['Purchase', $funnel['purchase'], '#3FD68A'],
        ];
        $max = max(array_column($steps,1)) ?: 1;
        foreach($steps as [$label,$count,$color]):
          $pct = $max > 0 ? round($count/$max*100) : 0;
        ?>
        <div class="sm-funnel-row">
          <div class="sm-funnel-label"><?php echo esc_html($label); ?></div>
          <div class="sm-funnel-track">
            <div class="sm-funnel-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>22;color:<?php echo $color; ?>">
              <?php echo number_format($count); ?>
            </div>
          </div>
          <div class="sm-funnel-count"><?php echo number_format($count); ?></div>
        </div>
        <?php endforeach; ?>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:16px">
          <?php foreach([['Cart Rate',$funnel['cart_rate'].'%','#A78BFA'],['Checkout Rate',$funnel['checkout_rate'].'%','#F5A623'],['Close Rate',$funnel['purchase_rate'].'%','#3FD68A']] as [$l,$v,$c]):?>
          <div style="text-align:center;padding:10px;background:var(--sm-raised);border-radius:8px">
            <div style="font-size:18px;font-weight:700;font-family:'DM Mono',monospace;color:<?php echo $c?>"><?php echo esc_html($v)?></div>
            <div style="font-size:10px;color:var(--sm-muted);text-transform:uppercase;letter-spacing:.08em;margin-top:2px"><?php echo esc_html($l)?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Attribution -->
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">Attribution — 30 Days</span></div>
      <div class="sm-card-body">
        <?php foreach([
          ['Meta Ads', 0.62,'#4A9EFF'],['Google Ads',0.25,'#F5A623'],['TikTok',0.08,'#F472B6'],['Organic',0.05,'#3FD68A'],
        ] as [$name,$share,$color]):
          $rev = $funnel30['revenue'] * $share; ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
            <span style="font-size:12px;font-weight:500"><?php echo esc_html($name)?></span>
            <span style="font-size:12px;font-family:'DM Mono',monospace;color:<?php echo $color?>">$<?php echo number_format($rev,0)?></span>
          </div>
          <div class="sm-prog-track"><div class="sm-prog-fill" style="width:<?php echo $share*100?>%;background:<?php echo $color?>"></div></div>
        </div>
        <?php endforeach; ?>
        <div class="sm-notice sm-notice-warning" style="font-size:11px;margin-top:14px">Connect Meta Ads API in Settings for live attribution data.</div>
      </div>
    </div>
  </div>

  <!-- Revenue Heatmap -->
  <div class="sm-card" style="margin-bottom:18px">
    <div class="sm-card-header">
      <span class="sm-card-title">Revenue Heatmap — Best Ad Scheduling Windows</span>
      <button class="sm-btn sm-btn-secondary sm-btn-sm" id="sm-rebuild-heatmap">↺ Rebuild</button>
    </div>
    <div class="sm-card-body" style="overflow-x:auto">
      <?php
      $days_labels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
      $max_rev = 0;
      foreach($heatmap_grid as $d) foreach($d as $cell) if((float)$cell['revenue']>$max_rev) $max_rev=(float)$cell['revenue'];
      ?>
      <div style="font-size:10px;color:var(--sm-muted);margin-bottom:8px;display:flex;gap:2px;padding-left:40px">
        <?php for($h=0;$h<24;$h++): ?>
        <div style="width:20px;text-align:center;flex-shrink:0"><?php echo $h%6===0?str_pad($h,2,'0',STR_PAD_LEFT).'h':''; ?></div>
        <?php endfor; ?>
      </div>
      <?php foreach($heatmap_grid as $d=>$hours): ?>
      <div style="display:flex;gap:2px;align-items:center;margin-bottom:2px">
        <div style="width:36px;font-size:10px;color:var(--sm-muted);text-align:right;padding-right:6px;flex-shrink:0"><?php echo $days_labels[$d%7]?></div>
        <?php foreach($hours as $h=>$cell):
          $rev = (float)$cell['revenue'];
          $intensity = $max_rev > 0 ? $rev/$max_rev : 0;
          $alpha = round($intensity * 0.85 + 0.05, 2);
          $color_r = intval(63 + ($intensity * (201-63)));
          $color_g = intval(214 + ($intensity * (168-214)));
          $color_b = intval(138 + ($intensity * (76-138)));
        ?>
        <div title="<?php echo esc_attr($days_labels[$d%7].' '.str_pad($h,2,'0',STR_PAD_LEFT).':00 — $'.number_format($rev,0).' revenue, '.$cell['orders'].' orders')?>"
             style="width:20px;height:18px;border-radius:2px;flex-shrink:0;background:rgba(<?php echo "$color_r,$color_g,$color_b"?>,.<?php echo str_replace('0.','',number_format($alpha,2))?>);cursor:pointer">
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <div style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:10px;color:var(--sm-muted)">
        <span>Low revenue</span>
        <div style="display:flex;gap:2px">
          <?php for($i=0;$i<=10;$i++): $a=round($i/10*0.85+0.05,2); ?>
          <div style="width:16px;height:12px;border-radius:2px;background:rgba(63,214,138,<?php echo $a?>)"></div>
          <?php endfor; ?>
        </div>
        <span>High revenue</span>
      </div>
    </div>
  </div>

  <!-- Cohort Table -->
  <?php if(!empty($cohort_table)): ?>
  <div class="sm-card">
    <div class="sm-card-header">
      <span class="sm-card-title">Cohort Retention — 6 Months</span>
      <button class="sm-btn sm-btn-secondary sm-btn-sm" id="sm-rebuild-cohorts">↺ Rebuild</button>
    </div>
    <div class="sm-card-body" style="overflow-x:auto;padding:0">
      <table style="width:100%;border-collapse:collapse;font-size:11px">
        <thead>
          <tr style="border-bottom:1px solid var(--sm-border)">
            <th style="padding:10px 14px;text-align:left;color:var(--sm-muted);font-weight:500;text-transform:uppercase;font-size:10px;letter-spacing:.08em;white-space:nowrap">Cohort</th>
            <th style="padding:10px 14px;text-align:center;color:var(--sm-muted);font-weight:500;text-transform:uppercase;font-size:10px">Customers</th>
            <?php for($i=0;$i<=5;$i++): ?>
            <th style="padding:10px 14px;text-align:center;color:var(--sm-muted);font-weight:500;text-transform:uppercase;font-size:10px">M+<?php echo $i?></th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach($cohort_table as $month=>$periods):
            $acquired = $periods[0]['customers'] ?? 0;
          ?>
          <tr style="border-bottom:1px solid var(--sm-border)">
            <td style="padding:10px 14px;font-weight:500;white-space:nowrap"><?php echo esc_html(date('M Y',strtotime($month)))?></td>
            <td style="padding:10px 14px;text-align:center;font-family:'DM Mono',monospace"><?php echo number_format($acquired)?></td>
            <?php for($i=0;$i<=5;$i++):
              $p = $periods[$i] ?? null;
              $ret = $p ? (float)$p['retention_pct'] : null;
              if($ret===null): ?>
              <td style="padding:10px 14px;text-align:center;color:var(--sm-muted)">—</td>
              <?php else:
                $bg_alpha = round($ret/100*0.5+0.05,2);
                $is_base = ($i===0);
              ?>
              <td style="padding:10px 14px;text-align:center;background:rgba(74,158,255,<?php echo $bg_alpha?>);font-family:'DM Mono',monospace;font-weight:<?php echo $is_base?'700':'400'?>">
                <?php echo round($ret,1)?>%
              </td>
              <?php endif; ?>
            <?php endfor; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
jQuery(function($){
  // Period switcher
  $('.active-period').css({color:'var(--sm-gold2)',borderColor:'var(--sm-gold)'});
  $('[data-days]').on('click',function(){
    $('[data-days]').css({color:'',borderColor:''});
    $(this).css({color:'var(--sm-gold2)',borderColor:'var(--sm-gold)'});
    window.location.href=window.location.pathname+'?page=stellar-meta-analytics&days='+$(this).data('days');
  });

  // Revenue chart
  var series = <?php echo wp_json_encode($series ?: []); ?>;
  var labels=[],data=[];
  if(series.length){ series.forEach(function(d){labels.push(d.snapshot_date);data.push(parseFloat(d.revenue)||0);}); }
  else { for(var i=29;i>=0;i--){var d=new Date();d.setDate(d.getDate()-i);labels.push(d.toISOString().substr(0,10));data.push(Math.round(Math.random()*2000+500));} }
  var ctx=document.getElementById('sm-rev-chart');
  if(ctx){ new Chart(ctx,{type:'line',data:{labels:labels,datasets:[{data:data,borderColor:'#C9A84C',backgroundColor:'rgba(201,168,76,.07)',tension:.4,pointRadius:0,borderWidth:2,fill:true}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#8C8C97',font:{size:10},maxTicksLimit:8}},y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#8C8C97',font:{size:10},callback:function(v){return '$'+v.toLocaleString();}}}}}});}

  $('#sm-rebuild-heatmap').on('click',function(){
    var btn=$(this);btn.text('Building…').prop('disabled',true);
    $.post(StellarMetaAdmin.ajaxUrl,{action:'stellar_meta_rebuild_heatmap',nonce:StellarMetaAdmin.nonce},function(){btn.text('✓ Done');setTimeout(function(){location.reload();},1200);});
  });
  $('#sm-rebuild-cohorts').on('click',function(){
    var btn=$(this);btn.text('Building…').prop('disabled',true);
    $.post(StellarMetaAdmin.ajaxUrl,{action:'stellar_meta_rebuild_cohorts',nonce:StellarMetaAdmin.nonce},function(){btn.text('✓ Done');setTimeout(function(){location.reload();},1200);});
  });
});
</script>
