# CaffeOnline Feed Sync

WooCommerce plugin for syncing the CaffeOnline supplier feed by GTIN/EAN/SKU.

**Aktuelle Plugin-Version:** `0.4.14`

## Features

- Batch sync for supplier stock, vendor SKU, and purchase prices.
- 3-hour supplier cron for stock and purchase-price updates.
- Purchase-price change log with source, old/new price, difference, and percentage change.
- Missing-product scan with draft-safe product import helpers.
- GitHub Release based updates through `yahnis-elsts/plugin-update-checker`.

## Update Distribution

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
composer run build
```

## Release

Create a semantic version tag:

```bash
git tag v0.4.14
git push origin v0.4.14
```

GitHub Actions builds `dist/caffeonline-feed-sync.zip` and attaches it to the release.
