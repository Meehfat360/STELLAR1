<?php
/**
 * Unmapped Catalog view — Enterprise UI.
 *
 * @var array<string, string> $filters
 * @var array{items: array<int, array<string, mixed>>, total: int, per_page: int, page: int} $listing
 * @var string $notice
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$total_pages = (int) ceil(max(1, $listing['total']) / max(1, $listing['per_page']));

$notice_messages = [
    'cleared'     => ['success', __('Product removed from Unmapped Catalog.', 'stellar-overstock-sync')],
    'clear_error' => ['error', __('Unable to clear the selected product.', 'stellar-overstock-sync')],
];

$status_labels = [
    'no_match'       => __('No Match', 'stellar-overstock-sync'),
    'failed'         => __('Failed', 'stellar-overstock-sync'),
    'timeout'        => __('Timeout', 'stellar-overstock-sync'),
    'blocked'        => __('Blocked', 'stellar-overstock-sync'),
    'no_price'       => __('No Price', 'stellar-overstock-sync'),
    'low_confidence' => __('Low Confidence', 'stellar-overstock-sync'),
];

$status_badge_classes = [
    'no_match'       => 'sos-badge-no_match',
    'failed'         => 'sos-badge-danger',
    'timeout'        => 'sos-badge-timeout',
    'blocked'        => 'sos-badge-blocked',
    'no_price'       => 'sos-badge-review',
    'low_confidence' => 'sos-badge-review',
];

$source_labels = [
    'overstock'           => 'Overstock',
    'bedbathandbeyond'    => 'BedBath&Beyond',
    'amazon'              => 'Amazon.com',
    'amazon_ca'           => 'Amazon.ca',
    'auto'                => 'Auto',
    'overstock_amazon'    => 'Overstock + Amazon.com',
    'overstock_amazon_ca' => 'Overstock + Amazon.ca',
];
?>
<div class="wrap sos-admin-wrap">

    <h1>
        <?php echo esc_html__('Unmapped Catalog', 'stellar-overstock-sync'); ?>
        <span class="sos-version-badge">
            <?php echo esc_html(number_format_i18n($listing['total'])); ?>
            <?php echo esc_html__('products', 'stellar-overstock-sync'); ?>
        </span>
    </h1>
    <p class="sos-page-subtitle">
        <?php echo esc_html__('Products the Chrome extension could not confidently map or price. A successful future match auto-removes a product from this list.', 'stellar-overstock-sync'); ?>
    </p>

    <?php if (isset($notice_messages[$notice])) : ?>
        <?php [$class, $message] = $notice_messages[$notice]; ?>
        <div class="notice notice-<?php echo esc_attr($class); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>

    <!-- Filter bar -->
    <div class="sos-card" style="padding:14px 20px;">
        <form method="get">
            <input type="hidden" name="page" value="sos-unmapped-catalog">
            <div class="sos-filter-bar">
                <div>
                    <label><?php echo esc_html__('Search', 'stellar-overstock-sync'); ?></label>
                    <input type="search" name="s"
                           value="<?php echo esc_attr((string) ($filters['s'] ?? '')); ?>"
                           placeholder="<?php echo esc_attr__('Title, ID, reason…', 'stellar-overstock-sync'); ?>"
                           style="min-width:200px;">
                </div>
                <div>
                    <label><?php echo esc_html__('Source', 'stellar-overstock-sync'); ?></label>
                    <select name="source">
                        <option value=""><?php echo esc_html__('All sources', 'stellar-overstock-sync'); ?></option>
                        <?php foreach ($source_labels as $key => $label) : ?>
                            <option value="<?php echo esc_attr($key); ?>"
                                <?php selected((string) ($filters['source'] ?? ''), $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?php echo esc_html__('Status', 'stellar-overstock-sync'); ?></label>
                    <select name="status">
                        <option value=""><?php echo esc_html__('All statuses', 'stellar-overstock-sync'); ?></option>
                        <?php foreach ($status_labels as $key => $label) : ?>
                            <option value="<?php echo esc_attr($key); ?>"
                                <?php selected((string) ($filters['status'] ?? ''), $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;gap:6px;align-items:flex-end;">
                    <button type="submit" class="sos-btn sos-btn-primary sos-btn-sm">
                        🔍 <?php echo esc_html__('Filter', 'stellar-overstock-sync'); ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sos-unmapped-catalog')); ?>"
                       class="sos-btn sos-btn-secondary sos-btn-sm">
                        <?php echo esc_html__('Reset', 'stellar-overstock-sync'); ?>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="sos-card" style="padding:0;overflow:hidden;">
        <div class="sos-table-wrap">
            <table class="sos-table sos-unmapped-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Product', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Source Tried', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Status', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Reason', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Confidence', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Best Match Found', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Last Attempt', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Attempts', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Actions', 'stellar-overstock-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ([] === $listing['items']) : ?>
                        <tr class="sos-empty-row">
                            <td colspan="9">
                                <?php echo esc_html__('No unmapped products. Products appear here only when the extension cannot confidently map or price them.', 'stellar-overstock-sync'); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($listing['items'] as $item) : ?>
                            <?php
                            $product_id   = (int) $item['woo_product_id'];
                            $product      = function_exists('wc_get_product') ? wc_get_product($product_id) : false;
                            $product_name = $product
                                ? $product->get_name()
                                : (string) ($item['product_title'] ?? ('#' . $product_id));
                            $status       = sanitize_key((string) ($item['unmapped_status'] ?? 'failed'));
                            $source       = sanitize_key((string) ($item['source'] ?? ''));
                            $attempts     = (int) get_post_meta($product_id, '_sos_extension_no_match_count', true);
                            $edit_url     = get_edit_post_link($product_id, '');
                            $confidence   = (string) ($item['confidence'] ?? '');
                            $badge_cls    = $status_badge_classes[$status] ?? 'sos-badge-neutral';
                            $status_label = $status_labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
                            $source_label = $source_labels[$source] ?? ($source ? ucfirst(str_replace('_', '.', $source)) : '—');
                            ?>
                            <tr>
                                <!-- Product -->
                                <td>
                                    <strong style="font-size:13px;">
                                        <?php echo esc_html($product_name); ?>
                                    </strong>
                                    <br><code>#<?php echo esc_html((string) $product_id); ?></code>
                                    <?php if ($edit_url) : ?>
                                        <br>
                                        <a href="<?php echo esc_url($edit_url); ?>"
                                           style="font-size:11px;color:var(--sos-primary);">
                                            ✏️ <?php echo esc_html__('Edit product', 'stellar-overstock-sync'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>

                                <!-- Source -->
                                <td>
                                    <span class="sos-badge sos-badge-neutral" style="font-size:11px;">
                                        <?php echo esc_html($source_label); ?>
                                    </span>
                                </td>

                                <!-- Status -->
                                <td>
                                    <span class="sos-badge <?php echo esc_attr($badge_cls); ?>">
                                        <?php echo esc_html($status_label); ?>
                                    </span>
                                </td>

                                <!-- Reason -->
                                <td style="font-size:12px;color:#6b7280;max-width:180px;line-height:1.5;">
                                    <?php echo esc_html((string) ($item['reason'] ?: '—')); ?>
                                </td>

                                <!-- Confidence -->
                                <td>
                                    <?php if ('' !== $confidence && '0' !== $confidence) : ?>
                                        <div class="sos-confidence">
                                            <div class="sos-confidence-bar">
                                                <div class="sos-confidence-fill"
                                                     style="width:<?php echo esc_attr((string) min(100, max(0, (int) $confidence))); ?>%;background-position:<?php echo esc_attr((string) (100 - min(100, (int) $confidence))); ?>% 0;">
                                                </div>
                                            </div>
                                            <?php echo esc_html($confidence . '%'); ?>
                                        </div>
                                    <?php else : ?>
                                        <span style="color:#9ca3af;">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Best match -->
                                <td style="max-width:220px;">
                                    <?php if (! empty($item['source_title'])) : ?>
                                        <span style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:3px;">
                                            <?php echo esc_html(mb_strimwidth((string) $item['source_title'], 0, 60, '…')); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (! empty($item['source_url'])) : ?>
                                        <a href="<?php echo esc_url((string) $item['source_url']); ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           style="font-size:11px;word-break:break-all;">
                                            <?php echo esc_html(mb_strimwidth((string) $item['source_url'], 0, 55, '…')); ?>
                                        </a>
                                    <?php else : ?>
                                        <span style="color:#9ca3af;font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Last attempt -->
                                <td style="font-size:12px;color:#6b7280;white-space:nowrap;">
                                    <?php echo esc_html((string) ($item['unmapped_at'] ?: '—')); ?>
                                </td>

                                <!-- Attempts -->
                                <td>
                                    <span class="sos-badge <?php echo $attempts >= 3 ? 'sos-badge-danger' : 'sos-badge-neutral'; ?>"
                                          style="font-size:11px;">
                                        <?php echo esc_html((string) $attempts); ?>
                                    </span>
                                </td>

                                <!-- Actions -->
                                <td style="white-space:nowrap;">
                                    <form method="post"
                                          action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                          class="sos-confirm-form"
                                          data-confirm="<?php echo esc_attr__('Remove this product from Unmapped Catalog? It may reappear if the extension fails on it again.', 'stellar-overstock-sync'); ?>">
                                        <?php wp_nonce_field('sos_clear_unmapped_product'); ?>
                                        <input type="hidden" name="action" value="sos_clear_unmapped_product">
                                        <input type="hidden" name="product_id"
                                               value="<?php echo esc_attr((string) $product_id); ?>">
                                        <button type="submit" class="sos-btn sos-btn-secondary sos-btn-sm">
                                            ✓ <?php echo esc_html__('Mark Reviewed', 'stellar-overstock-sync'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1) : ?>
            <div class="sos-pagination" style="padding:12px 16px;">
                <?php
                $base_args = array_filter([
                    'page'   => 'sos-unmapped-catalog',
                    'source' => sanitize_key((string) ($filters['source'] ?? '')),
                    'status' => sanitize_key((string) ($filters['status'] ?? '')),
                    's'      => sanitize_text_field((string) ($filters['s'] ?? '')),
                ]);
                echo wp_kses_post(paginate_links([
                    'base'      => add_query_arg(array_merge($base_args, ['paged' => '%#%']), admin_url('admin.php')),
                    'format'    => '',
                    'current'   => $listing['page'],
                    'total'     => $total_pages,
                    'type'      => 'plain',
                    'prev_text' => '‹',
                    'next_text' => '›',
                ]));
                ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Info card -->
    <div class="sos-card" style="background:#f9fafb;border-style:dashed;">
        <h2 style="color:#6b7280;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">
            <?php echo esc_html__('About This List', 'stellar-overstock-sync'); ?>
        </h2>
        <p style="font-size:13px;color:#6b7280;margin:0;line-height:1.7;">
            <?php echo esc_html__('Products land here when the Chrome extension cannot find a confident match on Overstock/Amazon — low confidence score, no price found, site blocked the request, or the listing simply does not exist on the source. Click "Mark Reviewed" to dismiss an item. Products with 3+ attempts are highlighted in red — consider handling them manually or removing from your catalog.', 'stellar-overstock-sync'); ?>
        </p>
    </div>

</div>
