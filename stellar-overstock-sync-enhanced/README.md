# Stellar Overstock Sync

Enterprise-oriented WooCommerce price sync foundation for mapped Overstock products.

## Current feature set
- Secure admin mapping CRUD
- CLI collector with job logging and locking
- Collected price review screen
- Manual single update
- Manual bulk update
- Review queue approve/reject workflow
- Update logs
- Scheduled hourly updater with settings

## Important limitations
- V1 currently supports simple, variable, variation, grouped, and external WooCommerce product types
- The collector parser is conservative and must be tested against real Overstock pages before live rollout
- Dry-run should remain enabled until validation is complete

See `DEPLOYMENT.md` for install and rollout instructions.


## Zyte integration
- Enable Zyte API in plugin settings to route collector requests through Zyte.
- Configure your Zyte API key and choose Browser HTML or HTTP Response Body mode.
