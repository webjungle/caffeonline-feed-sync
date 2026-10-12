# webjungle Supplier Sync

WooCommerce supplier sync for Thali, CaffeOnline, Ozii Caffè and TopItaly. The existing folder, option names and GitHub update channel remain compatible.

**Aktuelle Plugin-Version:** `0.6.4`

Ozii E.S.E.-Pads werden je Packungsvariante eingelesen und nur bestehenden, geprüften Produkten zugeordnet. Lagerpriorität: Thali → CaffeOnline → Ozii → TopItaly. Geprüfte UVP und EAN bleiben unabhängig vom aktiven Lager erhalten.

## Features

- Supplier priority: Thali → CaffeOnline → Ozii Caffè → TopItaly. Supplier SKUs, EANs, stock, purchase costs and provenance remain separate.
- Thali scans all eight coffee categories and is authoritative for linked product descriptions, specifications, images and gross UVP. Existing publication status is preserved; new items with verified UVP and images are published. The selling price equals UVP; purchase costs are separate and optional for publication.
- Thali dealer CSV (including the documented tab-separated Artikelnummer / EAN / Kunden-EP / Bestand export) imports net purchase costs with verification dates. Without a dealer feed, automatic runs update public product data and availability, but cannot refresh private costs. Dealer quantities expire after three hours; public availability then uses WooCommerce stock status without inventing quantities.
- Ozii scans Aroma, Faber and Didiesse with pagination. Unidentified articles require an explicit product link; no fuzzy title matching or use of public sale prices as purchase costs/UVP.
- Reviewed Ozii selling prices use the approved gross regular retail base plus 10 %, rounded to cents. The base remains editable in product inventory fields; repeated syncs never compound the markup. Fresh source prices are stored separately for manual review. Thali masters retain their UVP even when Ozii supplies stock. New unidentified Ozii imports remain drafts.
- Locked, resumable background batches retain data on transient errors. Confirmed removed Thali Aroma products are unpublished only when no replacement supplier is available.
- A supplier switch with missing purchase cost clears the previous supplier’s active cost and flags it as missing. Warehouse categories follow the active source, with at most one configured warehouse.
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
php tests/supplier-catalog.php
composer run build
```

## Release

Create a semantic version tag:

```bash
git tag v0.6.4
git push --atomic origin main v0.6.4
```

GitHub Actions builds `dist/caffeonline-feed-sync.zip` and attaches it to the release.
