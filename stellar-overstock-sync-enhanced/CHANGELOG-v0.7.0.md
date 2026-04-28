# Changelog v0.7.0

## New Features

### Inline Profit Rule Editor (Auto Links Tab)
- Admin can now set net profit % per product directly from the Auto Links table
- Four preset options: **15%, 20%, 25%, 30%** above collected source price
- Changes save instantly via AJAX — no page reload required
- Visual save button appears on change; green ✓ confirms save
- Price formula: `WooCommerce Price = Collected Source Price × (1 + Profit%)`
- Shock threshold and min/max bounds still apply

## UI/UX — Enterprise Dashboard Overhaul
- **Dashboard**: Stat cards with mode indicators (Dry Run / Auto Update / Next run)
- **Auto Links**: Enterprise table with inline profit editor, confidence meter, improved badges
- **Collected Prices**: Filterable table with status badges and clean layout
- **Review Queue**: Status tab navigation (Pending / Approved / Rejected / All)
- **Update Logs**: Full audit trail with colour-coded status badges
- **Settings**: Sectioned form with toggle rows, field descriptions, and Zyte grouping
- All tables use `.sos-table` with enterprise typography and hover states
- Responsive layout for mobile/tablet screens
- Consistent `.sos-btn` button system replacing raw WP button classes
- Paginator now uses styled `.sos-pagination` component

## Technical
- Added `ajax_update_profit_rule()` AJAX handler (nonce-secured, capability-checked)
- Allowed profit values: 15, 20, 25, 30 (server-side whitelist)
- Updated `enqueue_assets()` to pass `ajaxUrl` and `profitNonce` to JS
- JS refactored to handle profit select + save flow with spinner
