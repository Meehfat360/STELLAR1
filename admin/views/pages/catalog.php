<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) ); }
$settings = Stellar_Meta_Settings::instance();

global $wpdb;
$total      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'");
$optimized  = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . STELLAR_META_DB_PREFIX . "ai_product_data WHERE ai_title IS NOT NULL AND ai_title != ''");
$classified = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . STELLAR_META_DB_PREFIX . "ai_product_data WHERE purchase_type IS NOT NULL");

$recent_ai = $wpdb->get_results(
    "SELECT a.*, p.post_title, p.ID as pid
     FROM " . STELLAR_META_DB_PREFIX . "ai_product_data a
     JOIN {$wpdb->posts} p ON a.product_id = p.ID
     WHERE a.ai_title IS NOT NULL AND a.ai_title != ''
     ORDER BY a.generated_at DESC LIMIT 5",
    ARRAY_A
);
$feed_url = home_url('?stellar-meta-feed=1');
?>
<div class="sm-header">
  <div class="sm-header-left">
    <div class="sm-logo">S</div>
    <div>
      <div class="sm-header-title">Product Feed & Catalog</div>
      <div class="sm-header-sub">AI Titles · Smart Tags · Meta Catalog Sync</div>
    </div>
  </div>
  <div class="sm-header-right">
    <button class="sm-btn sm-btn-secondary sm-btn-sm" id="sm-gen-feed-btn">↻ Regenerate Feed</button>
    <a href="<?php echo esc_url($feed_url); ?>" target="_blank" class="sm-btn sm-btn-primary sm-btn-sm">↗ View Feed</a>
  </div>
</div>

<div class="sm-content">

  <!-- Stats -->
  <div class="sm-grid-4" style="margin-bottom:20px">
    <div class="sm-metric accent-gold">
      <div class="sm-metric-label">Total Products</div>
      <div class="sm-metric-value"><?php echo esc_html(number_format($total)); ?></div>
      <div class="sm-metric-delta flat">● in catalog</div>
    </div>
    <div class="sm-metric accent-blue">
      <div class="sm-metric-label">AI Titles</div>
      <div class="sm-metric-value" style="color:var(--sm-blue)"><?php echo esc_html(number_format($optimized)); ?></div>
      <div class="sm-metric-delta flat">● rewritten for ads</div>
    </div>
    <div class="sm-metric accent-purple">
      <div class="sm-metric-label">AI Classified</div>
      <div class="sm-metric-value" style="color:var(--sm-purple)"><?php echo esc_html(number_format($classified)); ?></div>
      <div class="sm-metric-delta flat">● with intent signals</div>
    </div>
    <div class="sm-metric">
      <div class="sm-metric-label">Coverage</div>
      <div class="sm-metric-value"><?php echo $total > 0 ? round($classified/$total*100) : 0; ?>%</div>
      <div class="sm-metric-delta <?php echo (max(1,$total) > 0 ? round($classified/max(1,$total)*100) : 0) > .8 ? 'up' : 'flat'; ?>">● of catalog</div>
    </div>
  </div>

  <div class="sm-grid-2">

    <!-- Feed Settings -->
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">Feed Configuration</span></div>
      <div class="sm-card-body">
        <div style="margin-bottom:16px">
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--sm-muted);font-family:'DM Mono',monospace;margin-bottom:8px">Feed URL (RSS / Meta Catalog)</div>
          <div class="sm-code" id="sm-feed-url-code"><?php echo esc_html($feed_url); ?></div>
          <button class="sm-btn sm-btn-ghost sm-btn-sm" style="margin-top:6px" onclick="navigator.clipboard.writeText('<?php echo esc_js($feed_url); ?>');this.textContent='Copied!'">📋 Copy URL</button>
        </div>

        <hr class="sm-sep">

        <?php $toggles = [
          ['AI Title Optimization', 'Rewrites product titles for Meta ad copy using GPT-4o.', $settings->get('ai_optimize_titles')],
          ['AI Description Rewrite','Generates benefit-focused descriptions for catalog ads.', $settings->get('ai_optimize_desc')],
          ['Auto-sync Catalog',     'Rebuilds feed daily and triggers AI batch on new products.', $settings->get('catalog_auto_sync')],
        ];
        foreach($toggles as [$label,$desc,$on]): ?>
        <div class="sm-toggle-row">
          <div class="sm-toggle-info">
            <div class="sm-toggle-label"><?php echo esc_html($label); ?></div>
            <div class="sm-toggle-desc"><?php echo esc_html($desc); ?></div>
          </div>
          <span class="sm-badge <?php echo $on ? 'sm-badge-green' : 'sm-badge-muted'; ?>"><?php echo $on ? 'ON' : 'OFF'; ?></span>
        </div>
        <?php endforeach; ?>

        <div style="margin-top:16px;display:flex;gap:10px">
          <button class="sm-btn sm-btn-primary sm-btn-sm" id="sm-run-ai-catalog">⚡ Run AI Batch (20)</button>
          <a href="<?php echo esc_url(admin_url('admin.php?page=stellar-meta-settings')); ?>" class="sm-btn sm-btn-secondary sm-btn-sm">Edit Settings</a>
        </div>
        <div id="sm-catalog-ai-msg" style="margin-top:10px"></div>
      </div>
    </div>

    <!-- AI Coverage -->
    <div class="sm-card">
      <div class="sm-card-header"><span class="sm-card-title">AI Optimization Coverage</span></div>
      <div class="sm-card-body">
        <?php
        $coverage_items = [
          ['Titles Rewritten',   $optimized,  $total, 'var(--sm-blue)'],
          ['Products Classified',$classified, $total, 'var(--sm-purple)'],
        ];
        foreach($coverage_items as [$label,$done,$tot,$color]):
          $pct = $tot > 0 ? round($done/$tot*100) : 0;
        ?>
        <div style="margin-bottom:16px">
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px">
            <span style="color:var(--sm-muted)"><?php echo esc_html($label); ?></span>
            <span class="sm-mono"><?php echo esc_html("$done / $tot ($pct%)"); ?></span>
          </div>
          <div class="sm-prog-track"><div class="sm-prog-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div></div>
        </div>
        <?php endforeach; ?>

        <hr class="sm-sep">

        <?php
        // Tag distribution
        $tag_dist = $wpdb->get_results(
            "SELECT auto_tags, COUNT(*) as cnt FROM " . STELLAR_META_DB_PREFIX . "ai_product_data WHERE auto_tags IS NOT NULL AND auto_tags != '' GROUP BY auto_tags ORDER BY cnt DESC LIMIT 8",
            ARRAY_A
        );
        if($tag_dist): ?>
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--sm-muted);font-family:'DM Mono',monospace;margin-bottom:10px">Auto-Tag Distribution</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          <?php foreach($tag_dist as $td):
            foreach(explode(',',$td['auto_tags']) as $tag): $tag=trim($tag); if(!$tag) continue; ?>
            <span class="sm-ai-tag sm-ai-trending"><?php echo esc_html($tag); ?> <span style="opacity:.6">(<?php echo esc_html($td['cnt']); ?>)</span></span>
          <?php endforeach; endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- AI Optimized Products Table -->
  <?php if($recent_ai): ?>
  <div class="sm-card" style="margin-top:18px">
    <div class="sm-card-header">
      <span class="sm-card-title">Recently AI-Optimized Products</span>
      <span class="sm-badge sm-badge-blue">GPT-4o</span>
    </div>
    <div class="sm-table-wrap">
      <table class="sm-table">
        <thead><tr><th>Product</th><th>AI Title</th><th>Tier</th><th>Intent</th><th>Tags</th><th>Confidence</th></tr></thead>
        <tbody>
          <?php foreach($recent_ai as $row): ?>
          <tr>
            <td style="font-size:12px;color:var(--sm-muted);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($row['post_title']); ?></td>
            <td style="font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($row['ai_title']); ?></td>
            <td><?php if($row['price_tier']): ?><span class="sm-ai-tag sm-ai-<?php echo esc_attr($row['price_tier'] === 'luxury' ? 'luxury' : 'budget'); ?>"><?php echo esc_html($row['price_tier']); ?></span><?php endif; ?></td>
            <td><?php if($row['purchase_type']): ?><span class="sm-ai-tag sm-ai-<?php echo esc_attr($row['purchase_type']); ?>"><?php echo esc_html($row['purchase_type']); ?></span><?php endif; ?></td>
            <td>
              <?php foreach(explode(',',$row['auto_tags']??'') as $t): $t=trim($t); if(!$t) continue; ?>
              <span class="sm-ai-tag sm-ai-trending"><?php echo esc_html($t); ?></span>
              <?php endforeach; ?>
            </td>
            <td class="sm-mono" style="color:var(--sm-muted)"><?php echo esc_html(round((float)$row['confidence_score']*100)); ?>%</td>
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
  $('#sm-gen-feed-btn').on('click', function(){
    $(this).prop('disabled',true).text('Generating…');
    $.post(ajaxurl, {action:'stellar_meta_generate_feed', nonce:StellarMetaAdmin.nonce}, function(res){
      $('#sm-gen-feed-btn').prop('disabled',false).text('↻ Regenerate Feed');
      if(res.success) alert('✓ Feed regenerated: ' + res.data.feed_url);
    });
  });

  $('#sm-run-ai-catalog').on('click', function(){
    $(this).prop('disabled',true).text('Running AI…');
    $.post(ajaxurl, {action:'stellar_meta_run_ai_batch', nonce:StellarMetaAdmin.nonce}, function(res){
      $('#sm-run-ai-catalog').prop('disabled',false).text('⚡ Run AI Batch (20)');
      if(res.success){
        $('#sm-catalog-ai-msg').html('<div class="sm-notice sm-notice-success">✓ Processed: '+res.data.processed+' · Failed: '+res.data.failed+'</div>');
        setTimeout(function(){ location.reload(); }, 1500);
      }
    });
  });
});
</script>
