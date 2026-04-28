# v0.6.1 — Unmapped Catalog

## Added
- New **Unmapped Catalog** admin tab under Stellar Sync.
- Persistent capture of products that the Chrome extension cannot confidently map or price.
- Stores failure source, status, reason, confidence, attempted source title/URL, last attempt time, and attempt count.
- Products are automatically removed from Unmapped Catalog after a successful future match/price collection.
- Admin action: **Mark Reviewed** to manually clear a product from the Unmapped Catalog.

## Captured failure cases
- No confident match found.
- Low-confidence match.
- Source page opened but no price found.
- Timeout / blocked / failed scrape result.

## Safety
- No WooCommerce price update behavior changed.
- Automatic Source Links and Collected Prices remain unchanged.
