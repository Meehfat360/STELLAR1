<?php
/**
 * Settings view — Enterprise UI.
 *
 * @var array<string, mixed> $settings
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$next_run = wp_next_scheduled('sos_hourly_updater_event');
$notice   = isset($_GET['notice']) ? sanitize_key((string) $_GET['notice']) : '';
$token    = (string) ($settings['collector_api_token'] ?? '');
?>
<div class="wrap sos-admin-wrap">

    <h1><?php echo esc_html__('Settings', 'stellar-overstock-sync'); ?></h1>
    <p class="sos-page-subtitle"><?php echo esc_html__('Control Chrome extension price sync, collector behavior, guardrails, and scheduling.', 'stellar-overstock-sync'); ?></p>

    <?php if ('saved' === $notice) : ?>
        <div class="notice notice-success is-dismissible"><p>✅ <?php echo esc_html__('Settings saved successfully.', 'stellar-overstock-sync'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('sos_save_settings'); ?>
        <input type="hidden" name="action" value="sos_save_settings">

        <!-- Collector Settings -->
        <div class="sos-card">
            <div class="sos-settings-section">
                <h3>⚡ <?php echo esc_html__('Collector & Extension', 'stellar-overstock-sync'); ?></h3>

                <div class="sos-form-row">
                    <label for="freshness_window_hours"><?php echo esc_html__('Freshness Window (hours)', 'stellar-overstock-sync'); ?></label>
                    <div>
                        <input name="freshness_window_hours" id="freshness_window_hours" type="number" min="1" max="720" value="<?php echo esc_attr((string) ($settings['freshness_window_hours'] ?? 72)); ?>">
                        <p class="sos-field-desc"><?php echo esc_html__('Collected prices older than this window are considered stale and skipped.', 'stellar-overstock-sync'); ?></p>
                    </div>
                </div>

                <div class="sos-form-row">
                    <label for="batch_size"><?php echo esc_html__('Batch Size', 'stellar-overstock-sync'); ?></label>
                    <div>
                        <input name="batch_size" id="batch_size" type="number" min="1" max="100" value="<?php echo esc_attr((string) ($settings['batch_size'] ?? 25)); ?>">
                        <p class="sos-field-desc"><?php echo esc_html__('Number of products the extension or collector processes per run.', 'stellar-overstock-sync'); ?></p>
                    </div>
                </div>

                <div class="sos-form-row">
                    <label for="request_timeout"><?php echo esc_html__('Request Timeout (s)', 'stellar-overstock-sync'); ?></label>
                    <input name="request_timeout" id="request_timeout" type="number" min="5" max="120" value="<?php echo esc_attr((string) ($settings['request_timeout'] ?? 20)); ?>">
                </div>

                <div class="sos-form-row">
                    <label for="request_retries"><?php echo esc_html__('Request Retries', 'stellar-overstock-sync'); ?></label>
                    <input name="request_retries" id="request_retries" type="number" min="0" max="5" value="<?php echo esc_attr((string) ($settings['request_retries'] ?? 2)); ?>">
                </div>

                <div class="sos-form-row">
                    <label for="request_delay_seconds"><?php echo esc_html__('Request Delay (s)', 'stellar-overstock-sync'); ?></label>
                    <div>
                        <input name="request_delay_seconds" id="request_delay_seconds" type="number" min="0" max="10" value="<?php echo esc_attr((string) ($settings['request_delay_seconds'] ?? 1)); ?>">
                        <p class="sos-field-desc"><?php echo esc_html__('Delay between requests to avoid rate limiting.', 'stellar-overstock-sync'); ?></p>
                    </div>
                </div>

                <div class="sos-form-row">
                    <label for="default_currency"><?php echo esc_html__('Default Currency', 'stellar-overstock-sync'); ?></label>
                    <input name="default_currency" id="default_currency" type="text" maxlength="3" style="max-width:80px;" value="<?php echo esc_attr((string) ($settings['default_currency'] ?? 'USD')); ?>">
                </div>
            </div>

            <!-- Zyte -->
            <div class="sos-settings-section">
                <h3>🌐 <?php echo esc_html__('Zyte API (Anti-Bot Proxy)', 'stellar-overstock-sync'); ?></h3>

                <label class="sos-toggle-row">
                    <input name="zyte_enabled" type="checkbox" value="1" <?php checked(! empty($settings['zyte_enabled'])); ?>>
                    <span class="sos-toggle-label">
                        <?php echo esc_html__('Use Zyte API', 'stellar-overstock-sync'); ?>
                        <span class="sos-toggle-desc"><?php echo esc_html__('Route collector requests through Zyte instead of direct HTTP.', 'stellar-overstock-sync'); ?></span>
                    </span>
                </label>

                <div class="sos-form-row" style="margin-top:12px;">
                    <label for="zyte_api_key"><?php echo esc_html__('Zyte API Key', 'stellar-overstock-sync'); ?></label>
                    <input name="zyte_api_key" id="zyte_api_key" type="password" class="code" style="max-width:380px;" value="<?php echo esc_attr((string) ($settings['zyte_api_key'] ?? '')); ?>" autocomplete="off" placeholder="sk-…">
                </div>

                <div class="sos-form-row">
                    <label for="zyte_mode"><?php echo esc_html__('Zyte Mode', 'stellar-overstock-sync'); ?></label>
                    <div>
                        <select name="zyte_mode" id="zyte_mode">
                            <option value="browser_html" <?php selected((string) ($settings['zyte_mode'] ?? 'browser_html'), 'browser_html'); ?>><?php echo esc_html__('Browser HTML (safer)', 'stellar-overstock-sync'); ?></option>
                            <option value="http_response_body" <?php selected((string) ($settings['zyte_mode'] ?? 'browser_html'), 'http_response_body'); ?>><?php echo esc_html__('HTTP Response Body (faster)', 'stellar-overstock-sync'); ?></option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Guardrails -->
            <div class="sos-settings-section">
                <h3>🛡️ <?php echo esc_html__('Price Guardrails', 'stellar-overstock-sync'); ?></h3>

                <div class="sos-form-row">
                    <label for="shock_threshold_pct"><?php echo esc_html__('Shock Threshold (%)', 'stellar-overstock-sync'); ?></label>
                    <div>
                        <input name="shock_threshold_pct" id="shock_threshold_pct" type="number" min="1" max="500" value="<?php echo esc_attr((string) ($settings['shock_threshold_pct'] ?? 25)); ?>">
                        <p class="sos-field-desc"><?php echo esc_html__('If source price moves more than this % from the previous reading, the update is paused and sent to the Review Queue.', 'stellar-overstock-sync'); ?></p>
                    </div>
                </div>

                <div class="sos-form-row">
                    <label for="stop_after_failures"><?php echo esc_html__('Stop After N Failures', 'stellar-overstock-sync'); ?></label>
                    <div>
                        <input name="stop_after_failures" id="stop_after_failures" type="number" min="1" max="500" value="<?php echo esc_attr((string) ($settings['stop_after_failures'] ?? 10)); ?>">
                        <p class="sos-field-desc"><?php echo esc_html__('Abort bulk update after this many consecutive failures.', 'stellar-overstock-sync'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Update behaviour -->
            <div class="sos-settings-section">
                <h3>🔄 <?php echo esc_html__('Update Behaviour', 'stellar-overstock-sync'); ?></h3>

                <label class="sos-toggle-row">
                    <input name="dry_run_mode" type="checkbox" value="1" <?php checked(! empty($settings['dry_run_mode'])); ?>>
                    <span class="sos-toggle-label" style="color:<?php echo ! empty($settings['dry_run_mode']) ? '#92400e' : 'inherit'; ?>;">
                        🧪 <?php echo esc_html__('Dry Run Mode', 'stellar-overstock-sync'); ?>
                        <span class="sos-toggle-desc"><?php echo esc_html__('Validate and log updates without actually saving WooCommerce prices. Safe for testing.', 'stellar-overstock-sync'); ?></span>
                    </span>
                </label>

                <label class="sos-toggle-row" style="margin-top:8px;">
                    <input name="auto_update_enabled" type="checkbox" value="1" <?php checked(! empty($settings['auto_update_enabled'])); ?>>
                    <span class="sos-toggle-label">
                        ⚡ <?php echo esc_html__('Auto Update Enabled', 'stellar-overstock-sync'); ?>
                        <span class="sos-toggle-desc"><?php echo esc_html__('Allow the scheduled hourly updater to process active mappings automatically.', 'stellar-overstock-sync'); ?></span>
                    </span>
                </label>

                <div style="margin-top:14px;padding:12px 16px;background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:6px;font-size:13px;color:#0369a1;">
                    <strong><?php echo esc_html__('Next scheduled run:', 'stellar-overstock-sync'); ?></strong>
                    <?php echo esc_html($next_run ? gmdate('Y-m-d H:i:s', (int) $next_run) . ' UTC' : __('Not scheduled', 'stellar-overstock-sync')); ?>
                </div>
            </div>

            <!-- API Token -->
            <div class="sos-settings-section" style="border:none;margin-bottom:0;padding-bottom:0;">
                <h3>🔑 <?php echo esc_html__('Collector / Chrome Extension API Token', 'stellar-overstock-sync'); ?></h3>
                <?php if (empty($token)) : ?>
                    <p style="color:#9ca3af;font-size:13px;"><?php echo esc_html__('Token will be auto-generated on first save.', 'stellar-overstock-sync'); ?></p>
                <?php else : ?>
                    <code style="display:block;word-break:break-all;padding:10px 14px;background:#f9fafb;border:1.5px solid #e5e7eb;border-radius:6px;font-size:12px;color:#374151;margin-bottom:8px;"><?php echo esc_html($token); ?></code>
                    <p class="sos-field-desc"><?php echo esc_html__('Paste into your Chrome extension SOS API Token field or collector config.js as apiToken. Keep this secret.', 'stellar-overstock-sync'); ?></p>
                <?php endif; ?>
            </div>

            <div style="padding-top:20px;border-top:1px solid #f3f4f6;margin-top:20px;">
                <button type="submit" class="sos-btn sos-btn-primary">
                    💾 <?php echo esc_html__('Save Settings', 'stellar-overstock-sync'); ?>
                </button>
            </div>
        </div>
    </form>
</div>
