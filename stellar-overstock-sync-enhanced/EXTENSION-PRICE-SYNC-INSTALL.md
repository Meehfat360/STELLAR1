# Stellar Overstock Sync 0.5.0 — Chrome Extension Price Sync

This build integrates the Chrome-extension bridge directly into the real plugin.

## New REST endpoints

All endpoints require the same Bearer token shown in **Stellar Sync → Settings → Collector / Chrome Extension API Token**.

```text
GET  /wp-json/sos/v1/extension/status
GET  /wp-json/sos/v1/extension/products?limit=5
POST /wp-json/sos/v1/extension/match
POST /wp-json/sos/v1/extension/price-result
```

Existing Playwright collector endpoints remain available:

```text
GET  /wp-json/sos/v1/collector/jobs
POST /wp-json/sos/v1/collector/result
```

## What changed

- Manual mapping UI is hidden.
- The old mapping table is still used internally as automatic source links.
- The Chrome extension can request 5 WooCommerce products.
- The extension can automatically create/update the Overstock source link.
- The extension can submit collected prices.
- The plugin stores collected prices, confidence data, and source titles.
- WooCommerce price updates still pass through the existing updater guardrails, dry-run mode, shock threshold, min/max rules, and review queue.

## Safe live test order

1. Backup the current plugin folder and database.
2. Upload/replace this plugin build.
3. Go to **Stellar Sync → Settings**.
4. Keep:
   - Dry Run Mode: ON
   - Auto Update Enabled: OFF
   - Batch Size: 5
5. Save settings and copy the API token.
6. Paste the token into the Chrome extension SOS API Token field.
7. Run:
   - Test SOS API
   - Preview 5 Products
   - Sync 5 Prices
8. Check **Collected Prices**, **Auto Links**, and **Update Logs**.
9. Only after results are correct, enable live updating.

## Important behavior

When Dry Run Mode is ON, the plugin validates and logs update attempts but does not change WooCommerce prices.

When Dry Run Mode is OFF and Auto Update Enabled is OFF, extension results are stored, but WooCommerce prices are not changed automatically. You can still use **Update Now** from Auto Links.

When Dry Run Mode is OFF and Auto Update Enabled is ON, successful high-confidence extension results can update WooCommerce prices through the existing updater.
