# v0.6.2 - Auto Mode Stability + Both-Sources Unmapped Label

- Restored and hardened Overstock matching inside the unified Chrome extension flow.
- Auto mode now tries Overstock first, then Amazon.com, before marking a product unmapped.
- Product page title/price is used to re-score weak search-result matches before failing.
- Unmapped Catalog now shows `Overstock + Amazon.com` when both sources were tried.
- Added richer failure details from the extension for easier manual cleanup.
