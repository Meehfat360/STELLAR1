<?php
/**
 * Update Logs view — Enterprise UI.
 *
 * @var array{items: array<int, array<string, mixed>>, total: int, per_page: int, page: int} $listing
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$total_pages  = (int) ceil(max(1, $listing['total']) / max(1, $listing['per_page']));
$current_page = $listing['page'];
?>
<div class="wrap sos-admin-wrap">

    <h1>
        <?php echo esc_html__('Update Logs', 'stellar-overstock-sync'); ?>
        <span class="sos-version-badge"><?php echo esc_html(number_format_i18n($listing['total'])); ?> <?php echo esc_html__('entries', 'stellar-overstock-sync'); ?></span>
    </h1>
    <p class="sos-page-subtitle"><?php echo esc_html__('Full audit trail of every WooCommerce price update attempt.', 'stellar-overstock-sync'); ?></p>

    <div class="sos-card" style="padding:0;overflow:hidden;">
        <div class="sos-table-wrap">
            <table class="sos-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('ID', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Product', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Source Price', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Computed Price', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Old Woo Price', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('New Woo Price', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Status', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Reason', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Created', 'stellar-overstock-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ([] === $listing['items']) : ?>
                        <tr class="sos-empty-row"><td colspan="9"><?php echo esc_html__('No update log entries found.', 'stellar-overstock-sync'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($listing['items'] as $row) : ?>
                            <?php
                            $us = (string) ($row['update_status'] ?? '');
                            $badge_map = [
                                'updated' => 'sos-badge-success',
                                'dry_run' => 'sos-badge-dry_run',
                                'review'  => 'sos-badge-review',
                                'failed'  => 'sos-badge-danger',
                                'skipped' => 'sos-badge-danger',
                            ];
                            $bcls = $badge_map[$us] ?? 'sos-badge-neutral';
                            ?>
                            <tr>
                                <td><code><?php echo esc_html((string) $row['id']); ?></code></td>
                                <td><code><?php echo esc_html((string) $row['woo_product_id']); ?></code></td>
                                <td><?php echo ! empty($row['source_price']) ? '$' . esc_html(number_format((float) $row['source_price'], 2)) : '<span style="color:#9ca3af;">—</span>'; ?></td>
                                <td><?php echo ! empty($row['computed_store_price']) ? '$' . esc_html(number_format((float) $row['computed_store_price'], 2)) : '<span style="color:#9ca3af;">—</span>'; ?></td>
                                <td><?php echo ! empty($row['old_woo_price']) ? '$' . esc_html(number_format((float) $row['old_woo_price'], 2)) : '<span style="color:#9ca3af;">—</span>'; ?></td>
                                <td><?php echo ! empty($row['new_woo_price']) ? '<strong>$' . esc_html(number_format((float) $row['new_woo_price'], 2)) . '</strong>' : '<span style="color:#9ca3af;">—</span>'; ?></td>
                                <td><span class="sos-badge <?php echo esc_attr($bcls); ?>"><?php echo esc_html(ucfirst($us)); ?></span></td>
                                <td><code style="font-size:11px;"><?php echo esc_html((string) ($row['update_reason'] ?? '—')); ?></code></td>
                                <td style="font-size:12px;color:#6b7280;white-space:nowrap;"><?php echo esc_html((string) ($row['created_at'] ?? '—')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1) : ?>
            <div class="sos-pagination" style="padding:12px 16px;">
                <?php
                echo wp_kses_post(paginate_links([
                    'base'      => add_query_arg(['page' => 'sos-update-logs', 'paged' => '%#%'], admin_url('admin.php')),
                    'format'    => '',
                    'current'   => $current_page,
                    'total'     => $total_pages,
                    'type'      => 'plain',
                    'prev_text' => '‹',
                    'next_text' => '›',
                ]));
                ?>
            </div>
        <?php endif; ?>
    </div>
</div>
