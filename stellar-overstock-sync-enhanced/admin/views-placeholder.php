<?php
/**
 * Placeholder admin view — Enterprise UI.
 *
 * @var string $page_title
 * @var string $page_message
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap sos-admin-wrap">
    <h1><?php echo esc_html($page_title); ?></h1>
    <div class="sos-card" style="text-align:center;padding:48px 32px;">
        <div style="font-size:40px;margin-bottom:12px;">🔧</div>
        <p style="color:#6b7280;font-size:14px;margin:0;"><?php echo esc_html($page_message); ?></p>
    </div>
</div>
