# FG Core Status Reporting 1.1.3

FG Core sends one aggregated technical installation report per WordPress site.

## Default endpoint

`https://lizenz.funckgroup-server.com/wp-json/fg-lizenz/v1/status`

Override in `wp-config.php`:

```php
define('FG_CORE_STATUS_ENDPOINT', 'https://example.com/wp-json/fg-lizenz/v1/status');
```

Disable completely:

```php
define('FG_CORE_STATUS_REPORTING', false);
```

## Data sent

- installation UUID and domain binding
- home URL and site URL
- WordPress, PHP and FG Core versions
- locale, timezone and environment type
- detected FUNCKGROUP plugins with version and active state

No admin email, users, posts, passwords, license keys or database contents are sent.

## Schedule

The first report is scheduled after roughly 2–15 minutes. Further reports run automatically once per day via WP-Cron. There is no manual send action in the WordPress admin.
