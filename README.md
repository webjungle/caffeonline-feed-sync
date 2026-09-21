# CaffeOnline Feed Sync

WooCommerce plugin for syncing the CaffeOnline supplier feed by GTIN/EAN/SKU.

**Aktuelle Plugin-Version:** `0.5.13`

## Features

- Multi-supplier stock logic: CaffeOnline is always primary. TopItaly is used only as a fallback when CaffeOnline is out of stock; TopItaly-only products are imported as separate draft products.
- TopItaly sitemap scanner with parallel product-page fetching, EAN/SKU matching, stock extraction, and manual per-product TopItaly purchase prices.
- Batch sync for CaffeOnline supplier stock, vendor SKU, and purchase prices.
- 3-hour supplier cron for stock and purchase-price updates.
- Every three hours, TopItaly starts a fresh sitemap cycle or resumes the current one. Database locking prevents concurrent cron/AJAX batches; failed sitemap discovery preserves existing supplier data.
- Previously known product URLs are checked even if they disappear from the sitemap. Confirmed HTTP 404/410 responses clear only that supplier's stock after the full scan, unless a working URL for the same EAN supplied fresh data. Timeouts, rate limits and server errors retain the last known stock.
- Purchase-price change log with source, old/new price, difference, and percentage change.
- Missing-product scan with draft-safe product import helpers.
- GitHub Release based updates through `yahnis-elsts/plugin-update-checker`.

## Update Distribution

For automatic TopItaly continuation, invoke WordPress cron from the server once per minute. The supplier refresh events retain their three-hour interval; the minute-level runner processes their pending batches without requiring an open admin page.

The plugin checks GitHub Releases from:

```text
https://github.com/webjungle/caffeonline-feed-sync
```

The release asset must be named:

```text
caffeonline-feed-sync.zip
```

The ZIP must contain one top-level folder:

```text
caffeonline-feed-sync/
```

## Local Checks

```bash
composer validate --strict
composer install
find . -path './vendor' -prune -o -path './dist' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
composer run validate-version
php tests/topitaly-cron.php
composer run build
```

## Release

Create a semantic version tag:

```bash
git tag v0.5.11
git push origin v0.5.11
```

GitHub Actions builds `dist/caffeonline-feed-sync.zip` and attaches it to the release.
