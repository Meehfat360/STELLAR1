# Stellar Overstock Sync v0.5.1

## Automatic Source Links update confirmation

This build improves the Automatic Source Links page so manual product updates are visually clear.

### Added

- New **Update Result** column in Automatic Source Links.
- Green **✓ Updated** badge when WooCommerce price was actually saved.
- Shows the new WooCommerce price beside the green badge.
- Shows timestamp of the last update attempt.
- Inline green tick beside the clicked **Update Now** button after a successful real update.
- Dry-run updates show **✓ Validated** instead of green Updated, so you know the price was checked but not saved.
- Review/failed/skipped states are also shown clearly.

### Important

If **Dry Run Mode** is ON, the row will show **✓ Validated**, not **✓ Updated**, because WooCommerce price is intentionally not changed.

To see the green **✓ Updated** status, use:

- Dry Run Mode: OFF
- Auto Update Enabled: as preferred
- Click **Update Now** on a row with a fresh collected source price
