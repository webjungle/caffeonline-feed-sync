# Releasing CaffeOnline Feed Sync

This plugin updates from GitHub Releases through `yahnis-elsts/plugin-update-checker`.

## Version Source

The plugin header in `caffeonline-feed-sync.php` is the source of truth:

```php
* Version: X.Y.Z
```

The following versions must match before a release tag is pushed:

- `Version` in `caffeonline-feed-sync.php`
- `COFS_VERSION` in `caffeonline-feed-sync.php`
- `Aktuelle Plugin-Version` in `README.md`
- `Stable tag` in `readme.txt`
- `version` in `package.json`, if this file is added later

Run this local check before tagging:

```bash
composer run validate-version
```

## Local Checks

Run these commands before creating a release tag:

```bash
composer validate --strict
composer install
find . -path './vendor' -prune -o -path './dist' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
composer run build
```

The release build runs:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

The generated file must be:

```text
dist/caffeonline-feed-sync.zip
```

The ZIP must contain exactly one top-level folder:

```text
caffeonline-feed-sync.zip
└── caffeonline-feed-sync/
    ├── caffeonline-feed-sync.php
    ├── assets/
    ├── includes/
    ├── vendor/
    └── readme.txt
```

`vendor/autoload.php` must be present in the ZIP. Customer installations must not need Composer.

## Creating a Release

Do not publish regular customer updates as GitHub pre-releases.

After all versions have been updated and local checks pass:

```bash
git tag vX.Y.Z
git push origin vX.Y.Z
```

The GitHub Actions workflow builds `caffeonline-feed-sync.zip` and attaches that exact asset to the GitHub Release.

The workflow fails if:

- the tag version does not match the plugin header,
- `COFS_VERSION` does not match,
- `README.md` does not match,
- `readme.txt` stable tag does not match,
- the release ZIP does not contain `caffeonline-feed-sync/vendor/autoload.php`,
- the ZIP has more than one top-level folder,
- development-only or backup files are included.

## Testing an Update

1. Install the previous release ZIP on a WordPress test installation.
2. Activate the plugin.
3. Confirm the CaffeOnline Sync admin pages still load.
4. Publish a newer non-prerelease GitHub Release.
5. Go to `Dashboard > Updates` or the plugin list and check for the update.
6. Run the update through the WordPress UI.
7. Confirm the plugin basename is `caffeonline-feed-sync/caffeonline-feed-sync.php`.
8. Confirm the CaffeOnline Sync admin pages still load after the update.

Versions installed before this update-checker integration must be updated manually once. Future releases can then appear through the normal WordPress update UI.

## Private Repository Note

Never store a GitHub token in PHP, JavaScript, Composer files, release artifacts, or the plugin ZIP.

If this repository is private, standard customer WordPress installations cannot anonymously download private GitHub Release assets. Before customer rollout, choose one of these distribution models:

- make release downloads publicly accessible through a public release repository,
- provide a dedicated update server,
- provide a secure licensed download endpoint.

Do not ship a hard-coded GitHub token with the plugin.
