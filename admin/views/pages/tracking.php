<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) ); }
$settings = Stellar_Meta_Settings::instance();
$mappings = $settings->custom_event_mappings();
?>
<div class="sm-header">
  <div class="sm-header-left">
    <div class="sm-logo">S</div>
    <div>
      <div class="sm-header-title">Tracking Engine</div>
      <div class="sm-header-sub">Pixel · CAPI · Custom Events · AI Enrichment</div>
    </div>
  </div>
  <div class="sm-header-right">
    <?php if ( $settings->is_pixel_enabled() ) : ?>
      <span class="sm-badge sm-badge-green"><span class="sm-dot"></span> Pixel Active</span>
    <?php endif; ?>
    <?php if ( $settings->is_capi_enabled() ) : ?>
      <span class="sm-badge sm-badge-blue"><span class="sm-dot"></span> CAPI Active</span>
    <?php endif; ?>
  </div>
</div>

<div class="sm-content">

  <!-- Status Overview -->
  <div class="sm-grid-4" style="margin-bottom:20px">
    <?php $items = [
      [ 'Meta Pixel',    $settings->is_pixel_enabled(),       $settings->pixel_id() ?: 'Not configured',    'var(--sm-blue)'   ],
      [ 'CAPI',          $settings->is_capi_enabled(),         $settings->access_token() ? 'Token set' : 'No token', 'var(--sm-green)' ],
      [ 'AI Enrichment', $settings->is_ai_enrichment_enabled(),$settings->openai_key() ? 'GPT-4o ready' : 'No API key', 'var(--sm-purple)'],
      [ 'Lazy Pixel',    $settings->is_lazy_pixel(),           'Deferred on user interaction',               'var(--sm-amber)'  ],
    ];
    foreach ( $items as [ $label, $active, $detail, $color ] ) : ?>
    <div class="sm-card">
      <div class="sm-card-body" style="display:flex;align-items:center;gap:12px;padding:14px 16px">
        <div style="width:10px;height:10px;border-radius:50%;background:<?php echo $active ? $color : 'var(--sm-border2)'; ?>;flex-shrink:0;box-shadow:<?php echo $active ? '0 0 8px '.$color : 'none'; ?>"></div>
        <div>
          <div style="font-size:13px;font-weight:600"><?php echo esc_html($label); ?></div>
          <div style="font-size:10px;color:var(--sm-muted);font-family:'DM Mono',monospace;margin-top:2px"><?php echo esc_html($detail); ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="sm-grid-2">

    <!-- Standard Events -->
    <div class="sm-card">
      <div class="sm-card-header">
        <span class="sm-card-title">Standard Events</span>
        <span class="sm-badge sm-badge-green">Auto-tracked</span>
      </div>
      <div class="sm-card-body" style="padding:0">
        <table class="sm-table">
          <thead><tr><th>Event</th><th>Trigger</th><th>Pixel</th><th>CAPI</th></tr></thead>
          <tbody>
            <?php $events = [
              [ 'PageView',         'Every page load',        true, false ],
              [ 'ViewContent',      'Product page view',      true, true  ],
              [ 'AddToCart',        'Add to cart click',      true, true  ],
              [ 'InitiateCheckout', 'Checkout page load',     true, true  ],
              [ 'Purchase',         'Order confirmation',     true, true  ],
            ];
            foreach ( $events as [ $name, $trigger, $pixel, $capi ] ) : ?>
            <tr>
              <td class="sm-mono"><?php echo esc_html($name); ?></td>
              <td style="font-size:12px;color:var(--sm-muted)"><?php echo esc_html($trigger); ?></td>
              <td><?php echo $pixel ? '<span class="sm-status sm-status-ok">✓</span>' : '<span class="sm-status sm-status-fail">✕</span>'; ?></td>
              <td><?php echo $capi  ? '<span class="sm-status sm-status-ok">✓</span>' : '<span class="sm-status sm-status-fail">✕</span>'; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- AI Enrichment Panel -->
    <div class="sm-card">
      <div class="sm-card-header">
        <span class="sm-card-title">AI Signal Enrichment</span>
        <button class="sm-btn sm-btn-secondary sm-btn-sm" id="sm-run-ai-batch">
          ⚡ Run Batch (20)
        </button>
      </div>
      <div class="sm-card-body">
        <?php if ( ! $settings->openai_key() ) : ?>
        <div class="sm-notice sm-notice-warning">Add your OpenAI API key in Settings to enable AI enrichment.</div>
        <?php else : ?>
        <div class="sm-notice sm-notice-info" style="margin-bottom:16px">
          AI classifies each product into purchase-type and price-tier signals, which ride inside event payloads sent to Meta for better ad optimization.
        </div>
        <?php endif; ?>

        <?php
        global $wpdb;
        $total_products = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'");
        $classified     = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . STELLAR_META_DB_PREFIX . "ai_product_data WHERE purchase_type IS NOT NULL");
        $pct = $total_products > 0 ? round($classified / $total_products * 100) : 0;
        ?>
        <div style="margin-bottom:16px">
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px">
            <span style="color:var(--sm-muted)">Products classified</span>
            <span class="sm-mono"><?php echo esc_html("$classified / $total_products"); ?></span>
          </div>
          <div class="sm-prog-track">
            <div class="sm-prog-fill" style="width:<?php echo esc_attr($pct); ?>%;background:var(--sm-purple)"></div>
          </div>
        </div>

        <!-- Sample classified products -->
        <?php
        $samples = $wpdb->get_results(
            "SELECT a.*, p.post_title FROM " . STELLAR_META_DB_PREFIX . "ai_product_data a
             JOIN {$wpdb->posts} p ON a.product_id = p.ID
             ORDER BY a.generated_at DESC LIMIT 4",
            ARRAY_A
        );
        if ( $samples ) : ?>
        <div style="display:flex;flex-direction:column;gap:8px">
          <?php foreach ( $samples as $s ) : ?>
          <div style="background:var(--sm-raised);border-radius:8px;padding:10px 12px;border:1px solid var(--sm-border)">
            <div style="font-size:12px;font-weight:500;margin-bottom:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($s['post_title']); ?></div>
            <div>
              <?php if ($s['price_tier'])    : ?><span class="sm-ai-tag sm-ai-<?php echo esc_attr($s['price_tier'] === 'luxury' ? 'luxury' : ($s['price_tier'] === 'budget' ? 'budget' : 'considered')); ?>"><?php echo esc_html($s['price_tier']); ?></span><?php endif; ?>
              <?php if ($s['purchase_type']) : ?><span class="sm-ai-tag sm-ai-<?php echo esc_attr($s['purchase_type']); ?>"><?php echo esc_html($s['purchase_type']); ?></span><?php endif; ?>
              <?php foreach ( explode(',', $s['auto_tags'] ?? '') as $tag ) : $tag = trim($tag); if ($tag) : ?>
              <span class="sm-ai-tag sm-ai-<?php echo esc_attr(in_array($tag,['trending','bestseller']) ? $tag : 'considered'); ?>"><?php echo esc_html($tag); ?></span>
              <?php endif; endforeach; ?>
              <span style="font-size:9px;color:var(--sm-subtle);font-family:'DM Mono',monospace;margin-left:6px"><?php echo esc_html(round((float)$s['confidence_score']*100)); ?>% confidence</span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div id="sm-ai-batch-result" style="margin-top:12px"></div>
      </div>
    </div>

  </div>

  <!-- Custom Event Parameter Builder -->
  <div class="sm-card" style="margin-top:18px">
    <div class="sm-card-header">
      <span class="sm-card-title">Custom Event Parameter Mappings</span>
      <button class="sm-btn sm-btn-primary sm-btn-sm" id="sm-save-mappings">💾 Save Mappings</button>
    </div>
    <div class="sm-card-body">
      <p style="font-size:13px;color:var(--sm-muted);margin-bottom:18px">
        Map WooCommerce data fields to Meta event parameters. These enriched parameters improve ad targeting and ROAS measurement.
      </p>

      <?php foreach ( [ 'Purchase', 'AddToCart', 'ViewContent', 'InitiateCheckout' ] as $event ) :
        $map = $mappings[$event] ?? [];
      ?>
      <div style="margin-bottom:20px">
        <div style="font-size:12px;font-weight:700;color:var(--sm-gold2);font-family:'DM Mono',monospace;margin-bottom:10px;letter-spacing:.04em"><?php echo esc_html($event); ?></div>
        <div class="sm-grid-3">
          <?php
          $default_params = [
            'Purchase'         => ['value','currency','content_ids','num_items','order_id'],
            'AddToCart'        => ['value','currency','content_ids','content_type'],
            'ViewContent'      => ['content_ids','content_name','value','currency'],
            'InitiateCheckout' => ['value','currency','num_items','content_ids'],
          ];
          $wc_sources = ['order_total','shop_currency','product_ids','item_count','product_price','product_id','product_name','product_category','product_margin','order_id'];
          foreach ( ($default_params[$event] ?? []) as $param ) :
            $current_val = $map[$param] ?? '';
          ?>
          <div class="sm-form-field">
            <label style="font-family:'DM Mono',monospace;color:var(--sm-gold2)"><?php echo esc_html($param); ?></label>
            <select class="sm-select event-mapping-select" data-event="<?php echo esc_attr($event); ?>" data-param="<?php echo esc_attr($param); ?>">
              <option value="">— not mapped —</option>
              <?php foreach ($wc_sources as $src) : ?>
              <option value="<?php echo esc_attr($src); ?>" <?php selected($current_val, $src); ?>><?php echo esc_html($src); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <div id="sm-mappings-msg" style="margin-top:10px"></div>
    </div>
  </div>

</div>

<script>
jQuery(function($){
  $('#sm-run-ai-batch').on('click', function(){
    $(this).prop('disabled',true).text('Running…');
    $.post(ajaxurl, {action:'stellar_meta_run_ai_batch', nonce:StellarMetaAdmin.nonce}, function(res){
      $('#sm-run-ai-batch').prop('disabled',false).text('⚡ Run Batch (20)');
      if(res.success){
        $('#sm-ai-batch-result').html('<div class="sm-notice sm-notice-success">✓ Processed: '+res.data.processed+' products · Failed: '+res.data.failed+'</div>');
      }
    });
  });

  $('#sm-save-mappings').on('click', function(){
    var mappings = {};
    $('.event-mapping-select').each(function(){
      var event = $(this).data('event');
      var param = $(this).data('param');
      var val   = $(this).val();
      if(val){
        if(!mappings[event]) mappings[event] = {};
        mappings[event][param] = val;
      }
    });
    $(this).prop('disabled',true).text('Saving…');
    $.post(ajaxurl, {
      action: 'stellar_meta_save_event_map',
      nonce:  StellarMetaAdmin.nonce,
      mappings: JSON.stringify(mappings)
    }, function(res){
      $('#sm-save-mappings').prop('disabled',false).text('💾 Save Mappings');
      $('#sm-mappings-msg').html('<div class="sm-notice sm-notice-success">✓ Event mappings saved.</div>');
      setTimeout(function(){ $('#sm-mappings-msg').html(''); }, 3000);
    });
  });
});
</script>
