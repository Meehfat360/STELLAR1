<?php
/**
 * Auto Links admin view — Enterprise UI with inline Profit Rule editor.
 *
 * @var array<string, array<int, string>> $option_sets
 * @var array{items: array<int, array<string, mixed>>, total: int, per_page: int, page: int} $listing
 * @var array<string, mixed> $form_values
 * @var array<int, array<string, mixed>> $latest_update_statuses
 * @var array<int, string> $form_errors
 * @var string $notice
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$total_pages = (int) ceil(max(1, $listing['total']) / max(1, $listing['per_page']));

$notice_messages = [
    'saved'               => ['success', __('Source link saved successfully.', 'stellar-overstock-sync')],
    'deleted'             => ['success', __('Source link deleted.', 'stellar-overstock-sync')],
    'error'               => ['error', __('Please fix the validation errors below.', 'stellar-overstock-sync')],
    'delete_error'        => ['error', __('Unable to delete the selected source link.', 'stellar-overstock-sync')],
    'update_success'      => ['success', urldecode((string) ($_GET['message'] ?? __('Product update completed.', 'stellar-overstock-sync')))],
    'update_error'        => ['error', urldecode((string) ($_GET['message'] ?? __('Product update failed.', 'stellar-overstock-sync')))],
    'bulk_update_success' => ['success', urldecode((string) ($_GET['message'] ?? __('Bulk update completed.', 'stellar-overstock-sync')))],
    'bulk_update_error'   => ['error', urldecode((string) ($_GET['message'] ?? __('Bulk update stopped.', 'stellar-overstock-sync')))],
    'profit_updated'      => ['success', __('Profit rule updated successfully.', 'stellar-overstock-sync')],
];

/* Fixed profit % options the admin can pick from */
$profit_options = [
    '15' => '15%',
    '20' => '20%',
    '25' => '25%',
    '30' => '30%',
];
?>
<div class="wrap sos-admin-wrap">

    <h1>
        <?php echo esc_html__('Auto Links', 'stellar-overstock-sync'); ?>
        <span class="sos-version-badge"><?php echo esc_html(number_format_i18n($listing['total'])); ?> <?php echo esc_html__('links', 'stellar-overstock-sync'); ?></span>
    </h1>
    <p class="sos-page-subtitle"><?php echo esc_html__('Extension-controlled source mappings. Set the profit rule per product, then hit Update Now to sync the WooCommerce price.', 'stellar-overstock-sync'); ?></p>

    <?php if (isset($notice_messages[$notice])) : ?>
        <?php [$class, $message] = $notice_messages[$notice]; ?>
        <div class="notice notice-<?php echo esc_attr($class); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
    <?php endif; ?>

    <?php if ([] !== $form_errors) : ?>
        <div class="notice notice-error">
            <p><strong><?php echo esc_html__('Please review the following:', 'stellar-overstock-sync'); ?></strong></p>
            <ul><?php foreach ($form_errors as $error) : ?><li><?php echo esc_html($error); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['processed_total'])) : ?>
        <div class="sos-summary-box">
            <span>📦 <?php echo esc_html__('Bulk run:', 'stellar-overstock-sync'); ?></span>
            <span><strong><?php echo esc_html((string) (int) ($_GET['processed_total'] ?? 0)); ?></strong> <?php echo esc_html__('total', 'stellar-overstock-sync'); ?></span>
            <span style="color:#057a55;"><strong><?php echo esc_html((string) (int) ($_GET['processed_success'] ?? 0)); ?></strong> <?php echo esc_html__('success', 'stellar-overstock-sync'); ?></span>
            <span style="color:#c81e1e;"><strong><?php echo esc_html((string) (int) ($_GET['processed_fail'] ?? 0)); ?></strong> <?php echo esc_html__('failed', 'stellar-overstock-sync'); ?></span>
            <span style="color:#6b7280;"><?php echo esc_html__('Job #', 'stellar-overstock-sync') . esc_html((string) (int) ($_GET['job_id'] ?? 0)); ?></span>
        </div>
    <?php endif; ?>

    <!-- Toolbar -->
    <div class="sos-card" style="padding:16px 20px;">
        <div class="sos-toolbar">
            <div class="sos-toolbar-left">
                <h2><?php echo esc_html__('Extension-Controlled Mapping', 'stellar-overstock-sync'); ?></h2>
                <p><?php echo esc_html__('The Chrome extension discovers products, creates source links automatically, collects prices, and sends results here. Set the profit % per row and click Update Now — or run Bulk Update to process all active links at once.', 'stellar-overstock-sync'); ?></p>
            </div>
            <div class="sos-toolbar-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sos-confirm-form" data-confirm="<?php echo esc_attr__('Run bulk update for all active and enabled source links?', 'stellar-overstock-sync'); ?>">
                    <?php wp_nonce_field('sos_update_bulk_mappings'); ?>
                    <input type="hidden" name="action" value="sos_update_bulk_mappings">
                    <button type="submit" class="sos-btn sos-btn-primary">
                        ⚡ <?php echo esc_html__('Bulk Update All', 'stellar-overstock-sync'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="sos-card" style="padding:0; overflow:hidden;">
        <div class="sos-table-wrap">
            <table class="sos-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('ID', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Woo Product', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Source', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Source URL', 'stellar-overstock-sync'); ?></th>
                        <th style="min-width:190px;"><?php echo esc_html__('Profit Rule', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Enabled', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Status', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Confidence', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Update Result', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Last Checked', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Actions', 'stellar-overstock-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ([] === $listing['items']) : ?>
                        <tr class="sos-empty-row">
                            <td colspan="11">
                                <?php echo esc_html__('No automatic source links yet. Run the Chrome extension Price Sync tab to discover your first products.', 'stellar-overstock-sync'); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($listing['items'] as $item) : ?>
                            <?php
                            $woo_product_id = (int) $item['woo_product_id'];
                            $product        = function_exists('wc_get_product') ? wc_get_product($woo_product_id) : false;
                            $product_name   = $product ? $product->get_name() : '';
                            $confidence     = get_post_meta($woo_product_id, '_sos_extension_match_confidence', true);
                            $latest_update  = $latest_update_statuses[(int) $item['id']] ?? null;
                            $latest_status  = is_array($latest_update) ? (string) ($latest_update['update_status'] ?? '') : '';
                            $just_updated   = isset($_GET['updated_mapping']) && (int) $_GET['updated_mapping'] === (int) $item['id'];
                            $row_class      = $just_updated && 'updated' === $latest_status ? 'sos-row-just-updated' : '';

                            /* Profit rule — current stored value */
                            $current_profit_type  = (string) ($item['profit_type'] ?? 'percent');
                            $current_profit_value = rtrim(rtrim((string) ($item['profit_value'] ?? '0'), '0'), '.');
                            /* Snap to nearest preset, or default to 20 */
                            $snapped = '20';
                            foreach (array_keys($profit_options) as $opt) {
                                if ((float) $current_profit_value === (float) $opt) {
                                    $snapped = $opt;
                                    break;
                                }
                            }

                            /* Status labels */
                            $status_labels = [
                                'updated' => '✓ ' . __('Updated', 'stellar-overstock-sync'),
                                'dry_run' => '✓ ' . __('Validated', 'stellar-overstock-sync'),
                                'review'  => __('Needs Review', 'stellar-overstock-sync'),
                                'failed'  => __('Failed', 'stellar-overstock-sync'),
                                'skipped' => __('Skipped', 'stellar-overstock-sync'),
                            ];
                            $status_label = $status_labels[$latest_status] ?? ucfirst($latest_status ?: 'Unknown');
                            ?>
                            <tr class="<?php echo esc_attr($row_class); ?>">

                                <!-- ID -->
                                <td><code><?php echo esc_html((string) $item['id']); ?></code></td>

                                <!-- Product -->
                                <td>
                                    <strong><?php echo esc_html($product_name ?: '#' . $woo_product_id); ?></strong>
                                    <br><code><?php echo esc_html((string) $woo_product_id); ?></code>
                                </td>

                                <!-- Source -->
                                <td>
                                    <span class="sos-badge sos-badge-neutral" style="font-size:11px;">
                                        <?php echo esc_html(ucwords(str_replace('_', '.', (string) ($item['source'] ?? 'overstock')))); ?>
                                    </span>
                                </td>

                                <!-- URL -->
                                <td style="max-width:240px; word-break:break-all; font-size:12px;">
                                    <a href="<?php echo esc_url((string) $item['source_url']); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php
                                        $url_display = (string) $item['source_url'];
                                        if (strlen($url_display) > 55) {
                                            $url_display = substr($url_display, 0, 52) . '…';
                                        }
                                        echo esc_html($url_display);
                                        ?>
                                    </a>
                                </td>

                                <!-- Profit Rule — INLINE EDITOR -->
                                <td>
                                    <div class="sos-profit-cell">
                                        <select
                                            class="sos-profit-select"
                                            data-mapping-id="<?php echo esc_attr((string) $item['id']); ?>"
                                            data-profit-type="<?php echo esc_attr($current_profit_type); ?>"
                                            data-original="<?php echo esc_attr($snapped); ?>"
                                        >
                                            <?php foreach ($profit_options as $val => $label) : ?>
                                                <option value="<?php echo esc_attr($val); ?>" <?php selected($snapped, $val); ?>>
                                                    <?php echo esc_html($label); ?> net profit
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="sos-profit-save-btn" title="<?php echo esc_attr__('Save profit rule', 'stellar-overstock-sync'); ?>">✓</button>
                                        <span class="sos-profit-saved-tick" title="<?php echo esc_attr__('Saved!', 'stellar-overstock-sync'); ?>">✓</span>
                                    </div>
                                </td>

                                <!-- Enabled -->
                                <td>
                                    <?php if (! empty($item['sync_enabled'])) : ?>
                                        <span class="sos-badge sos-badge-success">✓ <?php echo esc_html__('Yes', 'stellar-overstock-sync'); ?></span>
                                    <?php else : ?>
                                        <span class="sos-badge sos-badge-neutral"><?php echo esc_html__('No', 'stellar-overstock-sync'); ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Status -->
                                <td>
                                    <?php
                                    $item_status = (string) $item['status'];
                                    $status_badge_map = [
                                        'active' => 'sos-badge-success',
                                        'paused' => 'sos-badge-neutral',
                                        'review' => 'sos-badge-review',
                                    ];
                                    $badge_cls = $status_badge_map[$item_status] ?? 'sos-badge-neutral';
                                    ?>
                                    <span class="sos-badge <?php echo esc_attr($badge_cls); ?>">
                                        <?php echo esc_html(ucfirst($item_status)); ?>
                                    </span>
                                </td>

                                <!-- Confidence -->
                                <td>
                                    <?php if ('' !== (string) $confidence) : ?>
                                        <div class="sos-confidence">
                                            <div class="sos-confidence-bar">
                                                <div class="sos-confidence-fill" style="width:<?php echo esc_attr((string) min(100, max(0, (int) $confidence))); ?>%;background-position:<?php echo esc_attr((string) (100 - min(100, (int) $confidence))); ?>% 0;"></div>
                                            </div>
                                            <?php echo esc_html((string) $confidence . '%'); ?>
                                        </div>
                                    <?php else : ?>
                                        <span style="color:#9ca3af;">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Update Result -->
                                <td>
                                    <?php if (is_array($latest_update)) : ?>
                                        <?php $badge_class = 'sos-badge sos-status-' . sanitize_html_class($latest_status ?: 'unknown'); ?>
                                        <span class="<?php echo esc_attr($badge_class); ?>"><?php echo esc_html($status_label); ?></span>
                                        <?php if ('updated' === $latest_status && ! empty($latest_update['new_woo_price'])) : ?>
                                            <span class="sos-update-price">$<?php echo esc_html(number_format((float) $latest_update['new_woo_price'], 2)); ?></span>
                                        <?php elseif ('dry_run' === $latest_status && ! empty($latest_update['computed_store_price'])) : ?>
                                            <span class="sos-update-price">$<?php echo esc_html(number_format((float) $latest_update['computed_store_price'], 2)); ?></span>
                                        <?php endif; ?>
                                        <br><small style="color:#9ca3af;font-size:11px;"><?php echo esc_html((string) ($latest_update['created_at'] ?? '')); ?></small>
                                    <?php else : ?>
                                        <span class="sos-badge sos-status-none"><?php echo esc_html__('Not updated yet', 'stellar-overstock-sync'); ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Last Checked -->
                                <td style="font-size:12px;color:#6b7280;white-space:nowrap;">
                                    <?php echo esc_html((string) ($item['last_checked_at'] ?: '—')); ?>
                                </td>

                                <!-- Actions -->
                                <td style="white-space:nowrap;">
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                        <?php wp_nonce_field('sos_update_single_mapping'); ?>
                                        <input type="hidden" name="action" value="sos_update_single_mapping">
                                        <input type="hidden" name="mapping_id" value="<?php echo esc_attr((string) $item['id']); ?>">
                                        <button type="submit" class="sos-btn sos-btn-secondary sos-btn-sm">
                                            ↻ <?php echo esc_html__('Update Now', 'stellar-overstock-sync'); ?>
                                        </button>
                                    </form>

                                    <?php if ($just_updated && 'updated' === $latest_status) : ?>
                                        <span class="sos-inline-success" title="<?php echo esc_attr__('WooCommerce price updated!', 'stellar-overstock-sync'); ?>">✓</span>
                                    <?php endif; ?>

                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-left:4px;" class="sos-confirm-form" data-confirm="<?php echo esc_attr__('Delete this automatic source link?', 'stellar-overstock-sync'); ?>">
                                        <?php wp_nonce_field('sos_delete_mapping'); ?>
                                        <input type="hidden" name="action" value="sos_delete_mapping">
                                        <input type="hidden" name="mapping_id" value="<?php echo esc_attr((string) $item['id']); ?>">
                                        <button type="submit" class="sos-btn sos-btn-danger sos-btn-sm">
                                            <?php echo esc_html__('Delete', 'stellar-overstock-sync'); ?>
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
                echo wp_kses_post(paginate_links([
                    'base'      => add_query_arg('paged', '%#%'),
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

    <!-- Help note -->
    <div class="sos-card" style="background:#f9fafb;border-style:dashed;">
        <h2 style="color:#6b7280;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">
            <?php echo esc_html__('How the Profit Rule Works', 'stellar-overstock-sync'); ?>
        </h2>
        <p style="font-size:13px;color:#6b7280;margin:0;">
            <?php echo esc_html__('Select 15%, 20%, 25%, or 30% in the Profit Rule column and click the ✓ button to save. When you click Update Now, the new WooCommerce price = Collected Source Price + (Profit %). Example: source $100 + 20% = $120 store price. Shock threshold and min/max bounds still apply.', 'stellar-overstock-sync'); ?>
        </p>
    </div>

</div>
