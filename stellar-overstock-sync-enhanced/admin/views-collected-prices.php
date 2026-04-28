<?php
/**
 * Collected Prices view — Enterprise UI.
 *
 * @var array{items: array<int, array<string, mixed>>, total: int, per_page: int, page: int} $listing
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$total_pages = (int) ceil(max(1, $listing['total']) / max(1, $listing['per_page']));
$current_page = $listing['page'];
?>
<div class="wrap sos-admin-wrap">

    <h1>
        <?php echo esc_html__('Collected Prices', 'stellar-overstock-sync'); ?>
        <span class="sos-version-badge"><?php echo esc_html(number_format_i18n($listing['total'])); ?> <?php echo esc_html__('records', 'stellar-overstock-sync'); ?></span>
    </h1>
    <p class="sos-page-subtitle"><?php echo esc_html__('Raw price data scraped from source sites by the Chrome extension or collector.', 'stellar-overstock-sync'); ?></p>

    <!-- Filters -->
    <div class="sos-card" style="padding:14px 20px;">
        <form method="get">
            <input type="hidden" name="page" value="sos-collected-prices">
            <div class="sos-filter-bar">
                <div>
                    <label><?php echo esc_html__('Status', 'stellar-overstock-sync'); ?></label>
                    <select name="scrape_status">
                        <option value=""><?php echo esc_html__('All', 'stellar-overstock-sync'); ?></option>
                        <?php foreach (['success', 'failed', 'blocked', 'timeout'] as $s) : ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected((string) ($_GET['scrape_status'] ?? ''), $s); ?>><?php echo esc_html(ucfirst($s)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?php echo esc_html__('Product ID', 'stellar-overstock-sync'); ?></label>
                    <input type="text" name="woo_product_id" value="<?php echo esc_attr((string) ($_GET['woo_product_id'] ?? '')); ?>" placeholder="e.g. 42">
                </div>
                <div>
                    <label><?php echo esc_html__('Date From', 'stellar-overstock-sync'); ?></label>
                    <input type="date" name="date_from" value="<?php echo esc_attr((string) ($_GET['date_from'] ?? '')); ?>">
                </div>
                <div>
                    <label><?php echo esc_html__('Date To', 'stellar-overstock-sync'); ?></label>
                    <input type="date" name="date_to" value="<?php echo esc_attr((string) ($_GET['date_to'] ?? '')); ?>">
                </div>
                <div>
                    <button type="submit" class="sos-btn sos-btn-primary sos-btn-sm">🔍 <?php echo esc_html__('Filter', 'stellar-overstock-sync'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sos-collected-prices')); ?>" class="sos-btn sos-btn-secondary sos-btn-sm"><?php echo esc_html__('Reset', 'stellar-overstock-sync'); ?></a>
                </div>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="sos-card" style="padding:0;overflow:hidden;">
        <div class="sos-table-wrap">
            <table class="sos-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('ID', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Product', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Source URL', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Collected Price', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Stock', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Status', 'stellar-overstock-sync'); ?></th>
                        <th><?php echo esc_html__('Scraped At', 'stellar-overstock-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ([] === $listing['items']) : ?>
                        <tr class="sos-empty-row"><td colspan="7"><?php echo esc_html__('No collected price records found.', 'stellar-overstock-sync'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($listing['items'] as $row) : ?>
                            <?php
                            $scrape_status = (string) ($row['scrape_status'] ?? '');
                            $badge_map = [
                                'success' => 'sos-badge-success',
                                'failed'  => 'sos-badge-danger',
                                'blocked' => 'sos-badge-neutral',
                                'timeout' => 'sos-badge-neutral',
                            ];
                            $badge_cls = $badge_map[$scrape_status] ?? 'sos-badge-neutral';
                            ?>
                            <tr>
                                <td><code><?php echo esc_html((string) $row['id']); ?></code></td>
                                <td>
                                    <code><?php echo esc_html((string) $row['woo_product_id']); ?></code>
                                    <?php if (! empty($row['page_title'])) : ?>
                                        <br><small style="color:#6b7280;font-size:11px;"><?php echo esc_html(mb_strimwidth((string) $row['page_title'], 0, 50, '…')); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width:200px;word-break:break-all;font-size:11px;">
                                    <a href="<?php echo esc_url((string) ($row['source_url'] ?? '')); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html(mb_strimwidth((string) ($row['source_url'] ?? ''), 0, 55, '…')); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if (! empty($row['collected_price'])) : ?>
                                        <strong style="color:#111827;">$<?php echo esc_html(number_format((float) $row['collected_price'], 2)); ?></strong>
                                        <?php if (! empty($row['collected_currency'])) : ?>
                                            <small><?php echo esc_html((string) $row['collected_currency']); ?></small>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <span style="color:#9ca3af;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo ! empty($row['stock_status']) ? esc_html(ucfirst((string) $row['stock_status'])) : '<span style="color:#9ca3af;">—</span>'; ?>
                                </td>
                                <td><span class="sos-badge <?php echo esc_attr($badge_cls); ?>"><?php echo esc_html(ucfirst($scrape_status)); ?></span></td>
                                <td style="font-size:12px;color:#6b7280;white-space:nowrap;"><?php echo esc_html((string) ($row['scraped_at'] ?? '—')); ?></td>
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
                    'page'          => 'sos-collected-prices',
                    'scrape_status' => sanitize_key((string) ($_GET['scrape_status'] ?? '')),
                    'woo_product_id'=> absint((string) ($_GET['woo_product_id'] ?? 0)) ?: '',
                    'date_from'     => sanitize_text_field((string) ($_GET['date_from'] ?? '')),
                    'date_to'       => sanitize_text_field((string) ($_GET['date_to'] ?? '')),
                ]);
                echo wp_kses_post(paginate_links([
                    'base'      => add_query_arg(array_merge($base_args, ['paged' => '%#%']), admin_url('admin.php')),
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
