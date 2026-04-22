<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) ); }
$segments = Stellar_Meta_Segment_Builder::all();
?>
<div class="sm-header">
  <div class="sm-header-left">
    <div class="sm-logo">S</div>
    <div>
      <div class="sm-header-title">Audience Builder</div>
      <div class="sm-header-sub">Smart Segments · Meta Custom Audiences · Lookalikes</div>
    </div>
  </div>
  <div class="sm-header-right">
    <button class="sm-btn sm-btn-primary" id="sm-new-segment-btn">+ New Segment</button>
  </div>
</div>

<div class="sm-content">

  <!-- New Segment Modal (inline) -->
  <div id="sm-segment-form" style="display:none;margin-bottom:20px">
    <div class="sm-card">
      <div class="sm-card-header">
        <span class="sm-card-title">Define New Segment</span>
        <button class="sm-card-action" id="sm-cancel-segment">✕ Cancel</button>
      </div>
      <div class="sm-card-body">
        <div class="sm-form-grid">
          <div class="sm-form-field">
            <label>Segment Name</label>
            <input class="sm-input" type="text" id="seg-name" placeholder="e.g. High-Value Customers">
          </div>
          <div class="sm-form-field">
            <label>Description</label>
            <input class="sm-input" type="text" id="seg-desc" placeholder="Optional description">
          </div>
        </div>
        <div style="margin-top:16px">
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--sm-muted);font-family:'DM Mono',monospace;margin-bottom:10px">Segment Rules</div>
          <div class="sm-form-grid">
            <div class="sm-form-field">
              <label>Segment Type</label>
              <select class="sm-select" id="seg-type" onchange="StellarSegments.updateRuleFields()">
                <option value="high_value">High-Value Customers</option>
                <option value="abandoned_cart">Abandoned Cart</option>
                <option value="repeat_buyers">Repeat Buyers</option>
                <option value="recent_buyers">Recent Buyers</option>
                <option value="custom">Custom Rules</option>
              </select>
            </div>
            <div class="sm-form-field" id="seg-rule-1-wrap">
              <label id="seg-rule-1-label">Min. Lifetime Value ($)</label>
              <input class="sm-input" type="number" id="seg-rule-1" value="500" min="0">
            </div>
            <div class="sm-form-field" id="seg-rule-2-wrap">
              <label id="seg-rule-2-label">Min. Order Count</label>
              <input class="sm-input" type="number" id="seg-rule-2" value="2" min="1">
            </div>
            <div class="sm-form-field" id="seg-rule-3-wrap">
              <label id="seg-rule-3-label">Days Window</label>
              <input class="sm-input" type="number" id="seg-rule-3" value="60" min="1">
            </div>
          </div>
        </div>
        <div style="margin-top:20px;display:flex;gap:10px">
          <button class="sm-btn sm-btn-primary" id="sm-save-segment">💾 Create Segment</button>
        </div>
        <div id="sm-seg-msg" style="margin-top:10px"></div>
      </div>
    </div>
  </div>

  <!-- Segments List -->
  <div class="sm-card" style="margin-bottom:18px">
    <div class="sm-card-header">
      <span class="sm-card-title">Smart Segments</span>
      <span class="sm-badge sm-badge-muted"><?php echo count($segments); ?> segments</span>
    </div>
    <div class="sm-card-body" id="sm-segments-list">
      <?php if ( empty($segments) ) : ?>
      <div class="sm-empty">
        <div class="sm-empty-icon">🎯</div>
        <p>No segments yet. Create your first audience segment above.</p>
      </div>
      <?php else : ?>
      <div class="sm-segment-grid">
        <?php
        $icons = [
          'abandoned'    => [ '🛒', 'rgba(255,90,90,.12)' ],
          'high'         => [ '💰', 'rgba(201,168,76,.12)' ],
          'repeat'       => [ '🔁', 'rgba(63,214,138,.12)' ],
          'fashion'      => [ '👗', 'rgba(167,139,250,.12)' ],
          'lookalike'    => [ '🎯', 'rgba(74,158,255,.12)' ],
        ];
        foreach ( $segments as $seg ) :
          $icon_key = strtolower(substr($seg['name'],0,6));
          $icon_pair = $icons[$icon_key] ?? [ '👥', 'rgba(92,92,102,.12)' ];
          $synced = ! empty( $seg['meta_audience_id'] );
        ?>
        <div class="sm-segment-card" data-id="<?php echo esc_attr($seg['id']); ?>">
          <div class="sm-segment-icon" style="background:<?php echo esc_attr($icon_pair[1]); ?>"><?php echo $icon_pair[0]; ?></div>
          <div class="sm-segment-info">
            <div class="sm-segment-name"><?php echo esc_html($seg['name']); ?></div>
            <div class="sm-segment-meta">
              <?php
              $rules = json_decode($seg['rules'], true);
              echo esc_html( implode(' · ', array_map(
                fn($k,$v) => $k . ': ' . $v,
                array_keys($rules), array_values($rules)
              )));
              ?>
            </div>
            <?php if ( $synced ) : ?>
            <div style="margin-top:4px"><span class="sm-badge sm-badge-green" style="font-size:9px">Synced to Meta · ID: <?php echo esc_html($seg['meta_audience_id']); ?></span></div>
            <?php endif; ?>
          </div>
          <div class="sm-segment-count"><?php echo esc_html( number_format((int)$seg['member_count']) ); ?></div>
          <div class="sm-segment-actions">
            <button class="sm-btn sm-btn-secondary sm-btn-sm sm-sync-segment" data-id="<?php echo esc_attr($seg['id']); ?>">→ Meta</button>
            <button class="sm-btn sm-btn-ghost sm-btn-sm sm-delete-segment" data-id="<?php echo esc_attr($seg['id']); ?>">✕</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Pre-built segment templates -->
  <div class="sm-section-title">Quick Templates</div>
  <div class="sm-grid-3">
    <?php $templates = [
      [ '🛒', 'Abandoned Cart — 72h',    'var(--sm-red)',    'Orders with no purchase in 72h',    '{"abandoned_cart_days":3}' ],
      [ '💰', 'High-Value Customers',     'var(--sm-gold)',   'LTV > $500, 3+ orders',             '{"min_ltv":500,"min_order_count":3}' ],
      [ '🔁', 'Repeat Buyers (60d)',      'var(--sm-green)',  '2+ purchases in last 60 days',      '{"min_order_count":2,"days_since_last_order":60}' ],
    ];
    foreach ( $templates as [ $icon, $name, $color, $desc, $rules ] ) : ?>
    <div class="sm-card" style="cursor:pointer;transition:border-color .2s" onmouseover="this.style.borderColor='<?php echo esc_attr($color);?>'" onmouseout="this.style.borderColor=''" onclick="StellarSegments.prefill('<?php echo esc_attr($name); ?>','<?php echo esc_attr($rules); ?>')">
      <div class="sm-card-body" style="text-align:center;padding:22px">
        <div style="font-size:26px;margin-bottom:8px"><?php echo $icon; ?></div>
        <div style="font-size:13px;font-weight:700;margin-bottom:5px"><?php echo esc_html($name); ?></div>
        <div style="font-size:11px;color:var(--sm-muted)"><?php echo esc_html($desc); ?></div>
        <div style="margin-top:12px"><span class="sm-badge sm-badge-muted">+ Use Template</span></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div>

<script>
var StellarSegments = {
  typeConfigs: {
    high_value:      { r1l:'Min LTV ($)', r1v:500, r2l:'Min Orders', r2v:3, r3l:'—', r3v:0, r3h:true },
    abandoned_cart:  { r1l:'Days Since Cart', r1v:3, r2l:'—', r2v:0, r2h:true, r3l:'—', r3v:0, r3h:true },
    repeat_buyers:   { r1l:'Min Orders', r1v:2, r2l:'Days Window', r2v:60, r3l:'—', r3v:0, r3h:true },
    recent_buyers:   { r1l:'Days Since Purchase', r1v:30, r2l:'Min Orders', r2v:1, r3l:'—', r3v:0, r3h:true },
    custom:          { r1l:'Min Orders', r1v:1, r2l:'Min LTV ($)', r2v:0, r3l:'Days Window', r3v:30, r3h:false },
  },
  updateRuleFields: function(){
    var type = document.getElementById('seg-type').value;
    var cfg = this.typeConfigs[type] || this.typeConfigs.custom;
    document.getElementById('seg-rule-1-label').textContent = cfg.r1l;
    document.getElementById('seg-rule-1').value = cfg.r1v;
    document.getElementById('seg-rule-2-label').textContent = cfg.r2l;
    document.getElementById('seg-rule-2').value = cfg.r2v;
    document.getElementById('seg-rule-2-wrap').style.display = cfg.r2h ? 'none' : '';
    document.getElementById('seg-rule-3-label').textContent = cfg.r3l;
    document.getElementById('seg-rule-3').value = cfg.r3v;
    document.getElementById('seg-rule-3-wrap').style.display = cfg.r3h ? 'none' : '';
  },
  buildRules: function(){
    var type = document.getElementById('seg-type').value;
    var r1 = parseFloat(document.getElementById('seg-rule-1').value)||0;
    var r2 = parseFloat(document.getElementById('seg-rule-2').value)||0;
    var r3 = parseFloat(document.getElementById('seg-rule-3').value)||0;
    var map = {
      high_value:     { min_ltv: r1, min_order_count: r2 },
      abandoned_cart: { abandoned_cart_days: r1 },
      repeat_buyers:  { min_order_count: r1, days_since_last_order: r2 },
      recent_buyers:  { days_since_last_order: r1, min_order_count: r2 },
      custom:         { min_order_count: r1, min_ltv: r2, days_since_last_order: r3 },
    };
    return map[type] || {};
  },
  prefill: function(name, rulesJson){
    document.getElementById('seg-name').value = name;
    document.getElementById('sm-segment-form').style.display = '';
    document.getElementById('seg-name').scrollIntoView({behavior:'smooth'});
  }
};

jQuery(function($){
  $('#sm-new-segment-btn').on('click', function(){
    $('#sm-segment-form').slideToggle();
  });
  $('#sm-cancel-segment').on('click', function(){
    $('#sm-segment-form').slideUp();
  });

  $('#sm-save-segment').on('click', function(){
    var name  = $('#seg-name').val().trim();
    if(!name){ alert('Please enter a segment name.'); return; }
    var rules = StellarSegments.buildRules();
    $(this).prop('disabled',true).text('Creating…');
    $.post(ajaxurl,{
      action:'stellar_meta_create_segment',
      nonce: StellarMetaAdmin.nonce,
      name: name,
      desc: $('#seg-desc').val(),
      rules: JSON.stringify(rules)
    }, function(res){
      if(res.success){
        $('#sm-seg-msg').html('<div class="sm-notice sm-notice-success">✓ Segment created. Refreshing…</div>');
        setTimeout(function(){ location.reload(); }, 1000);
      } else {
        $('#sm-seg-msg').html('<div class="sm-notice sm-notice-error">Error: '+res.data+'</div>');
      }
    });
  });

  $(document).on('click', '.sm-sync-segment', function(){
    var id = $(this).data('id');
    var $btn = $(this);
    $btn.prop('disabled',true).text('Syncing…');
    $.post(ajaxurl,{action:'stellar_meta_sync_segment',nonce:StellarMetaAdmin.nonce,segment_id:id},function(res){
      $btn.prop('disabled',false).text('→ Meta');
      var cls = res.success ? 'sm-notice-success' : 'sm-notice-error';
      var msg = res.success ? '✓ Synced '+res.data.count+' users to Meta audience '+res.data.audience_id : 'Error: '+res.data.message;
      $btn.closest('.sm-segment-card').after('<div class="sm-notice '+cls+'" style="margin:6px 0">'+msg+'</div>');
    });
  });

  $(document).on('click', '.sm-delete-segment', function(){
    if(!confirm(StellarMetaAdmin.i18n.confirm_delete)) return;
    var id = $(this).data('id');
    $.post(ajaxurl,{action:'stellar_meta_delete_segment',nonce:StellarMetaAdmin.nonce,segment_id:id},function(){
      $('[data-id="'+id+'"]').fadeOut(300, function(){ $(this).remove(); });
    });
  });
});
</script>
