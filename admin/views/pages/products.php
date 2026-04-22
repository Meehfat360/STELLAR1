<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'stellar-meta' ) ); }
$days      = isset($_GET['days']) ? max(1,min(365,(int)sanitize_text_field(wp_unslash($_GET['days'])))) : 30;
$tab       = sanitize_key($_GET['ptab'] ?? 'scorecard');
$scorecard = Stellar_Meta_Product_Analytics::get_scorecard($days);
$trending  = Stellar_Meta_Product_Analytics::get_trending(10);
$crosssell = Stellar_Meta_Product_Analytics::get_cross_sell_pairs(15);
?>
<div class="sm-header">
  <div class="sm-header-left">
    <div class="sm-logo">S</div>
    <div>
      <div class="sm-header-title">Product Analytics</div>
      <div class="sm-header-sub">Performance · Attribution · Cross-sell · Trending</div>
    </div>
  </div>
  <div class="sm-header-right">
    <button class="sm-btn sm-btn-secondary sm-btn-sm <?php echo $days===7?'active-period':''; ?>" onclick="window.location.href='?page=stellar-meta-products&days=7&ptab=<?php echo $tab?>'">7d</button>
    <button class="sm-btn sm-btn-secondary sm-btn-sm <?php echo $days===30?'active-period':''; ?>" onclick="window.location.href='?page=stellar-meta-products&days=30&ptab=<?php echo $tab?>'">30d</button>
    <button class="sm-btn sm-btn-secondary sm-btn-sm <?php echo $days===90?'active-period':''; ?>" onclick="window.location.href='?page=stellar-meta-products&days=90&ptab=<?php echo $tab?>'">90d</button>
  </div>
</div>

<div class="sm-content">

  <!-- Tab nav -->
  <div style="display:flex;gap:2px;border-bottom:1px solid var(--sm-border);margin-bottom:20px">
    <?php foreach([['scorecard','Performance Scorecard'],['trending','Trending Now'],['crosssell','Cross-sell Pairs']] as [$t,$l]): ?>
    <a href="?page=stellar-meta-products&days=<?php echo $days ?>&ptab=<?php echo $t ?>"
       style="padding:8px 16px;font-size:12px;font-weight:500;text-decoration:none;border-bottom:2px solid <?php echo $tab===$t?'var(--sm-gold)':'transparent' ?>;color:<?php echo $tab===$t?'var(--sm-text)':'var(--sm-muted)' ?>;transition:all .15s">
       <?php echo esc_html($l) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if($tab==='scorecard'): ?>
  <!-- Performance Scorecard -->
  <div class="sm-card">
    <div class="sm-card-header">
      <span class="sm-card-title">Product Performance — Last <?php echo $days ?> Days</span>
      <span class="sm-badge sm-badge-muted"><?php echo count($scorecard) ?> products</span>
    </div>
    <div class="sm-card-body" style="padding:0;overflow-x:auto">
      <?php if(empty($scorecard)): ?>
      <div style="padding:40px;text-align:center;color:var(--sm-muted)">No product data yet. Events will populate as customers browse and purchase.</div>
      <?php else: ?>
      <table class="sm-table">
        <thead>
          <tr>
            <th style="text-align:left">Product</th>
            <th>Views</th>
            <th>Cart</th>
            <th>Purchases</th>
            <th>Revenue</th>
            <th>AOV</th>
            <th>View CVR</th>
            <th>Cart CVR</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($scorecard as $p):
          $cvr_color = $p['view_cvr'] >= 5 ? 'var(--sm-green)' : ($p['view_cvr'] >= 2 ? 'var(--sm-amber)' : 'var(--sm-red)');
        ?>
        <tr>
          <td>
            <div style="font-weight:500;font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?php echo esc_html($p['product_name']) ?>
            </div>
            <div style="font-size:10px;color:var(--sm-muted)">ID: <?php echo (int)$p['product_id'] ?></div>
          </td>
          <td style="font-family:'DM Mono',monospace"><?php echo number_format($p['views']) ?></td>
          <td style="font-family:'DM Mono',monospace"><?php echo number_format($p['add_to_cart']) ?></td>
          <td style="font-family:'DM Mono',monospace;font-weight:600"><?php echo number_format($p['purchases']) ?></td>
          <td style="font-family:'DM Mono',monospace;color:var(--sm-gold2);font-weight:600">$<?php echo number_format($p['revenue'],0) ?></td>
          <td style="font-family:'DM Mono',monospace">$<?php echo number_format($p['aov'],2) ?></td>
          <td><span style="font-family:'DM Mono',monospace;color:<?php echo $cvr_color ?>;font-weight:600"><?php echo $p['view_cvr'] ?>%</span></td>
          <td style="font-family:'DM Mono',monospace"><?php echo $p['cart_cvr'] ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <?php elseif($tab==='trending'): ?>
  <!-- Trending Products -->
  <div class="sm-card">
    <div class="sm-card-header">
      <span class="sm-card-title">Trending — This Week vs Last Week</span>
    </div>
    <div class="sm-card-body" style="padding:0">
      <?php if(empty($trending)): ?>
      <div style="padding:40px;text-align:center;color:var(--sm-muted)">No trend data available yet.</div>
      <?php else: ?>
      <table class="sm-table">
        <thead><tr><th style="text-align:left">Product</th><th>This Week</th><th>Last Week</th><th>Change</th><th>Trend</th></tr></thead>
        <tbody>
        <?php foreach($trending as $p):
          $change = (int)$p['change_pct'];
          $color  = $change > 0 ? 'var(--sm-green)' : ($change < -20 ? 'var(--sm-red)' : 'var(--sm-amber)');
          $arrow  = $change > 0 ? '▲' : '▼';
        ?>
        <tr>
          <td><div style="font-weight:500;font-size:12px"><?php echo esc_html($p['product_name']) ?></div></td>
          <td style="font-family:'DM Mono',monospace;font-weight:600"><?php echo number_format($p['this_week']) ?></td>
          <td style="font-family:'DM Mono',monospace;color:var(--sm-muted)"><?php echo number_format($p['prev_week']) ?></td>
          <td style="font-family:'DM Mono',monospace;color:<?php echo $color ?>;font-weight:600"><?php echo $arrow . abs($change) ?>%</td>
          <td>
            <?php if($change > 100): ?><span class="sm-badge" style="background:rgba(63,214,138,.15);color:var(--sm-green)">🔥 Hot</span>
            <?php elseif($change > 20): ?><span class="sm-badge" style="background:rgba(74,158,255,.15);color:var(--sm-blue)">↑ Rising</span>
            <?php elseif($change < -20): ?><span class="sm-badge" style="background:rgba(239,68,68,.15);color:#f87171">↓ Falling</span>
            <?php else: ?><span class="sm-badge sm-badge-muted">→ Stable</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <?php elseif($tab==='crosssell'): ?>
  <!-- Cross-sell Pairs -->
  <div class="sm-card">
    <div class="sm-card-header">
      <span class="sm-card-title">Cross-sell Pairs — Frequently Bought Together</span>
      <span class="sm-badge sm-badge-muted">Last 90 days</span>
    </div>
    <div class="sm-card-body" style="padding:0">
      <?php if(empty($crosssell)): ?>
      <div style="padding:40px;text-align:center;color:var(--sm-muted)">Not enough order data yet for cross-sell analysis (needs 30+ orders).</div>
      <?php else: ?>
      <table class="sm-table">
        <thead><tr><th style="text-align:left">Product A</th><th style="text-align:left">Product B</th><th>Co-purchases</th><th>Opportunity</th></tr></thead>
        <tbody>
        <?php foreach($crosssell as $pair):
          $pa = get_post($pair['product_a']); $pb = get_post($pair['product_b']);
          $name_a = $pa ? $pa->post_title : 'Product '.$pair['product_a'];
          $name_b = $pb ? $pb->post_title : 'Product '.$pair['product_b'];
          $co = (int)$pair['co_purchases'];
        ?>
        <tr>
          <td><div style="font-size:12px;font-weight:500"><?php echo esc_html($name_a) ?></div></td>
          <td><div style="font-size:12px;font-weight:500"><?php echo esc_html($name_b) ?></div></td>
          <td style="font-family:'DM Mono',monospace;font-weight:600;color:var(--sm-gold2)"><?php echo number_format($co) ?></td>
          <td>
            <?php if($co >= 20): ?><span class="sm-badge" style="background:rgba(63,214,138,.15);color:var(--sm-green)">Bundle Candidate</span>
            <?php else: ?><span class="sm-badge sm-badge-muted">Monitor</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div>
