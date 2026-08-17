# CaffeOnline Feed Sync

WooCommerce plugin for syncing the CaffeOnline supplier feed by GTIN/EAN/SKU.

**Aktuelle Plugin-Version:** `0.5.11`

## Features

- Multi-supplier stock logic: CaffeOnline is always primary. TopItaly is used only as a fallback when CaffeOnline is out of stock; TopItaly-only products are imported as separate draft products.
- TopItaly sitemap scanner with parallel product-page fetching, EAN/SKU matching, stock extraction, and manual per-product TopItaly purchase prices.
- Batch sync for CaffeOnline supplier stock, vendor SKU, and purchase prices.
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
git tag v0.5.11
git push origin v0.5.11
```

GitHub Actions builds `dist/caffeonline-feed-sync.zip` and attaches it to the release.
