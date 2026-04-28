<?php
/**
 * Uninstall handler.
 *
 * By default this removes only plugin options. Custom data tables are preserved to avoid destructive data loss.
 * If you want tables dropped on uninstall later, gate it behind an explicit setting or constant.
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('sos_plugin_version');
delete_option('sos_do_activation_redirect');
delete_option('sos_settings');
