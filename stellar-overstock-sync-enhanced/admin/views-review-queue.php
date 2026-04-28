<?php
/**
 * Review Queue view — Enterprise UI.
 *
 * @var array{items: array<int, array<string, mixed>>, total: int, per_page: int, page: int} $listing
 * @var string $notice
 * @var string $message
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$total_pages  = (int) ceil(max(1, $listing['total']) / max(1, $listing['per_page']));
$current_page = $listing['page'];
$status_filter = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : 'pending';

$notice_messages = [
    'review_approved' => ['success', __('Review item approved and price updated.', 'stellar-overstock-sync')],
    'review_rejected' => ['success', __('Review item rejected.', 'stellar-overstock-sync')],
    'review_error'    => ['error', $message ?: __('Action failed.', 'stellar-overstock-sync')],
];
?>
<div class="wrap sos-admin-wrap">

    <h1>
        <?php echo esc_html__('Review Queue', 'stellar-overstock-sync'); ?>
        <span class="sos-version-badge"><?php echo esc_html(number_format_i18n($listing['total'])); ?> <?php echo esc_html__('items', 'stellar-overstock-sync'); ?></span>
    </h1>
    <p class="sos-page-subtitle"><?php echo esc_html__('Price updates that exceeded the shock threshold or require manual approval before going live.', 'stellar-overstock-sync'); ?></p>

    <?php if (isset($notice_messages[$notice])) : ?>
        <?php [$class, $msg] = $notice_messages[$notice]; ?>
        <div class="notice notice-<?php echo esc_attr($class); ?> is-dismissible"><p><?php echo esc_html((string) $msg); ?></p></div>
    <?php endif; ?>

    <!-- Status tabs -->
    <div class="sos-card" style="padding:10px 16px;">
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach (['pending' => '⏳ Pending', 'approved' => '✅ Approved', 'rejected' => '✗ Rejected', '' => '📋 All'] as $s => $label) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sos-review-queue&status=' . $s)); ?>"
                   class="sos-btn sos-btn-sm <?php echo $status_filter === $s ? 'sos-btn-primary' : 'sos-btn-secondary'; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Table -->
    <div class="sos-card" style="padding:0;overflow:hidden;">
        <div class="sos-table-wrap">
            <table class="sos-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('ID', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Product', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Source Price', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Computed Price', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Reason', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Status', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Created', 'stellar-overstock-sync'); ?></th>
                        <?php if ('pending' === $status_filter || '' === $status_filter) : ?>
                            <th><?php echo esc_html__('Actions', 'stellar-overstock-sync'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ([] === $listing['items']) : ?>
                        <tr class="sos-empty-row">
                            <td colspan="8"><?php echo esc_html__('No items in the review queue.', 'stellar-overstock-sync'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($listing['items'] as $item) : ?>
                            <?php
                            $item_status = (string) ($item['status'] ?? '');
                            $badge_map = [
                                'pending'  => 'sos-badge-pending',
                                'approved' => 'sos-badge-approved',
                                'rejected' => 'sos-badge-rejected',
                            ];
                            $badge_cls = $badge_map[$item_status] ?? 'sos-badge-neutral';
                            $product = function_exists('wc_get_product') ? wc_get_product((int) ($item['woo_product_id'] ?? 0)) : false;
                            $product_name = $product ? $product->get_name() : '#' . ($item['woo_product_id'] ?? '?');
                            ?>
                            <tr>
                                <td><code><?php echo esc_html((string) $item['id']); ?></code></td>
                                <td>
                                    <strong><?php echo esc_html($product_name); ?></strong>
                                    <br><code><?php echo esc_html((string) $item['woo_product_id']); ?></code>
                                </td>
                                <td><strong>$<?php echo esc_html(number_format((float) ($item['source_price'] ?? 0), 2)); ?></strong></td>
                                <td><strong>$<?php echo esc_html(number_format((float) ($item['computed_store_price'] ?? 0), 2)); ?></strong></td>
                                <td><code style="font-size:11px;"><?php echo esc_html((string) ($item['reason'] ?? '')); ?></code></td>
                                <td><span class="sos-badge <?php echo esc_attr($badge_cls); ?>"><?php echo esc_html(ucfirst($item_status)); ?></span></td>
                                <td style="font-size:12px;color:#6b7280;white-space:nowrap;"><?php echo esc_html((string) ($item['created_at'] ?? '—')); ?></td>
                                <?php if ('pending' === $status_filter || '' === $status_filter) : ?>
                                    <td style="white-space:nowrap;">
                                        <?php if ('pending' === $item_status) : ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                                <?php wp_nonce_field('sos_review_approve'); ?>
                                                <input type="hidden" name="action" value="sos_review_approve">
                                                <input type="hidden" name="review_id" value="<?php echo esc_attr((string) $item['id']); ?>">
                                                <button type="submit" class="sos-btn sos-btn-sm" style="background:#def7ec;color:#057a55;border-color:#a7f3d0;">✓ <?php echo esc_html__('Approve', 'stellar-overstock-sync'); ?></button>
                                            </form>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:4px;" class="sos-confirm-form" data-confirm="<?php echo esc_attr__('Reject this review item?', 'stellar-overstock-sync'); ?>">
                                                <?php wp_nonce_field('sos_review_reject'); ?>
                                                <input type="hidden" name="action" value="sos_review_reject">
                                                <input type="hidden" name="review_id" value="<?php echo esc_attr((string) $item['id']); ?>">
                                                <button type="submit" class="sos-btn sos-btn-danger sos-btn-sm">✗ <?php echo esc_html__('Reject', 'stellar-overstock-sync'); ?></button>
                                            </form>
                                        <?php else : ?>
                                            <span style="color:#9ca3af;font-size:12px;"><?php echo esc_html(ucfirst($item_status)); ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
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
                    'base'      => add_query_arg(['page' => 'sos-review-queue', 'status' => $status_filter, 'paged' => '%#%'], admin_url('admin.php')),
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
