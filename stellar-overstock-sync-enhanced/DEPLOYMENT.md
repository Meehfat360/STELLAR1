# Deployment Guide

## Install
1. Upload the plugin ZIP in WordPress admin.
2. Activate the plugin.
3. Confirm the `Stellar Sync` menu appears.

## Recommended rollout
1. Use staging first.
2. Keep dry-run ON.
3. Add 5 mappings.
4. Run the CLI collector manually.
5. Review `Collected Prices`.
6. Test single updates in dry-run.
7. Test bulk updates in dry-run.
8. Review `Review Queue` and `Update Logs`.
9. Turn dry-run OFF only after validation.
10. Enable scheduled auto-update only after confidence is high.

## CLI collector
```bash
php collect-overstock-prices.php --batch=10
```

## Suggested server cron for collector
```bash
0 * * * * /usr/bin/php /var/www/html/wp-content/plugins/stellar-overstock-sync-stage1/cli/collect-overstock-prices.php >> /var/log/overstock-collector.log 2>&1
```

## Suggested server cron for WordPress cron trigger
```bash
*/5 * * * * wget -q -O - "https://YOUR-DOMAIN/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

## Safety reminders
- Back up database before first live write.
- Confirm only simple products are mapped in this version.
- Watch for parser failures and review queue growth before enabling auto-update.


## Zyte integration
- Enable Zyte API in plugin settings to route collector requests through Zyte.
- Configure your Zyte API key and choose Browser HTML or HTTP Response Body mode.
