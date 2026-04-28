<?php
/**
 * Dashboard view — Enterprise UI.
 *
 * @var array<string, int|string> $stats
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$dry_run     = ! empty($stats['dry_run_mode']);
$auto_update = ! empty($stats['auto_update_enabled']);
$next_run    = ! empty($stats['next_updater_run'])
    ? gmdate('Y-m-d H:i', (int) $stats['next_updater_run']) . ' UTC'
    : __('Not scheduled', 'stellar-overstock-sync');
?>
<div class="wrap sos-admin-wrap">

    <h1>
        <?php echo esc_html__('Stellar Overstock Sync', 'stellar-overstock-sync'); ?>
        <span class="sos-version-badge">v<?php echo esc_html((string) $stats['plugin_version']); ?></span>
    </h1>
    <p class="sos-page-subtitle"><?php echo esc_html__('Enterprise price sync dashboard — monitor your WooCommerce ↔ Overstock pricing pipeline.', 'stellar-overstock-sync'); ?></p>

    <!-- Mode indicators -->
    <div class="sos-mode-bar">
        <span class="sos-mode-pill <?php echo $dry_run ? 'warn-yes' : 'active-yes'; ?>">
            <?php echo $dry_run
                ? esc_html__('🧪 Dry Run ON — prices NOT saved', 'stellar-overstock-sync')
                : esc_html__('✅ Live Mode — prices ARE saved', 'stellar-overstock-sync'); ?>
        </span>
        <span class="sos-mode-pill <?php echo $auto_update ? 'active-yes' : 'active-no'; ?>">
            <?php echo $auto_update
                ? esc_html__('⚡ Auto Update: Enabled', 'stellar-overstock-sync')
                : esc_html__('⏸ Auto Update: Disabled', 'stellar-overstock-sync'); ?>
        </span>
        <span class="sos-mode-pill active-no" style="background:#f0f9ff;color:#0369a1;border-color:#bae6fd;">
            🕐 <?php echo esc_html__('Next run: ', 'stellar-overstock-sync') . esc_html($next_run); ?>
        </span>
    </div>

    <!-- Stats grid -->
    <div class="sos-stat-grid">
        <div class="sos-stat-card sos-stat-primary">
            <span class="sos-stat-label"><?php echo esc_html__('Mapped Products', 'stellar-overstock-sync'); ?></span>
            <span class="sos-stat-value"><?php echo esc_html(number_format_i18n((int) $stats['map_count'])); ?></span>
            <span class="sos-stat-meta"><?php echo esc_html__('Active source links', 'stellar-overstock-sync'); ?></span>
        </div>
        <div class="sos-stat-card">
            <span class="sos-stat-label"><?php echo esc_html__('Collected Records', 'stellar-overstock-sync'); ?></span>
            <span class="sos-stat-value"><?php echo esc_html(number_format_i18n((int) $stats['collected_count'])); ?></span>
            <span class="sos-stat-meta"><?php echo esc_html__('Total price scrapes', 'stellar-overstock-sync'); ?></span>
        </div>
        <div class="sos-stat-card <?php echo (int) $stats['review_pending'] > 0 ? 'sos-stat-warning' : ''; ?>">
            <span class="sos-stat-label"><?php echo esc_html__('Pending Reviews', 'stellar-overstock-sync'); ?></span>
            <span class="sos-stat-value"><?php echo esc_html(number_format_i18n((int) $stats['review_pending'])); ?></span>
            <span class="sos-stat-meta"><?php echo esc_html__('Awaiting admin approval', 'stellar-overstock-sync'); ?></span>
        </div>
        <div class="sos-stat-card">
            <span class="sos-stat-label"><?php echo esc_html__('Update Logs', 'stellar-overstock-sync'); ?></span>
            <span class="sos-stat-value"><?php echo esc_html(number_format_i18n((int) $stats['update_log_count'])); ?></span>
            <span class="sos-stat-meta"><?php echo esc_html__('Total update events', 'stellar-overstock-sync'); ?></span>
        </div>
        <div class="sos-stat-card">
            <span class="sos-stat-label"><?php echo esc_html__('Job Records', 'stellar-overstock-sync'); ?></span>
            <span class="sos-stat-value"><?php echo esc_html(number_format_i18n((int) $stats['job_count'])); ?></span>
            <span class="sos-stat-meta"><?php echo esc_html__('Bulk sync jobs run', 'stellar-overstock-sync'); ?></span>
        </div>
        <div class="sos-stat-card">
            <span class="sos-stat-label"><?php echo esc_html__('DB Schema', 'stellar-overstock-sync'); ?></span>
            <span class="sos-stat-value" style="font-size:18px;"><?php echo esc_html((string) $stats['installed_version']); ?></span>
            <span class="sos-stat-meta"><?php echo esc_html__('Installed version', 'stellar-overstock-sync'); ?></span>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="sos-card">
        <h2><?php echo esc_html__('Quick Navigation', 'stellar-overstock-sync'); ?></h2>
        <div class="sos-quick-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=sos-mappings')); ?>" class="sos-btn sos-btn-primary">
                🔗 <?php echo esc_html__('Auto Links', 'stellar-overstock-sync'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sos-unmapped-catalog')); ?>" class="sos-btn sos-btn-secondary">
                📋 <?php echo esc_html__('Unmapped Catalog', 'stellar-overstock-sync'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sos-collected-prices')); ?>" class="sos-btn sos-btn-secondary">
                💰 <?php echo esc_html__('Collected Prices', 'stellar-overstock-sync'); ?>
            </a>
            <?php if ((int) $stats['review_pending'] > 0) : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sos-review-queue')); ?>" class="sos-btn" style="background:#fef3c7;color:#92400e;border-color:#fde68a;font-weight:700;">
                ⚠️ <?php echo esc_html(sprintf(__('%d Pending Reviews', 'stellar-overstock-sync'), (int) $stats['review_pending'])); ?>
            </a>
            <?php endif; ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sos-update-logs')); ?>" class="sos-btn sos-btn-secondary">
                📊 <?php echo esc_html__('Update Logs', 'stellar-overstock-sync'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sos-settings')); ?>" class="sos-btn sos-btn-secondary">
                ⚙️ <?php echo esc_html__('Settings', 'stellar-overstock-sync'); ?>
            </a>
        </div>
    </div>

    <!-- How it works -->
    <div class="sos-card">
        <h2><?php echo esc_html__('How the Pipeline Works', 'stellar-overstock-sync'); ?></h2>
        <p><?php echo esc_html__('Chrome extension → discovers products → sends source URL + price → plugin stores & computes → applies profit rule → updates WooCommerce price (guarded by shock threshold and min/max bounds).', 'stellar-overstock-sync'); ?></p>
        <p class="description"><?php echo esc_html__('Keep Dry Run Mode ON while testing. Disable it once you trust collected data and review queue behavior.', 'stellar-overstock-sync'); ?></p>
    </div>

</div>
