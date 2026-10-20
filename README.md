# whm-scripts

PHP CLI scripts for managing a WHM/cPanel reseller fleet: sync domains and
accounts into one SQLite DB (`websites.sqlite`), reconcile them, audit WordPress
installs, and make bulk changes (DNS, PHP versions).

## Setup

```bash
cp .env.example .env     # fill in WHM_* and SYNERGY_*
php sync_whm_accounts.php
```

All config goes through `bootstrap.php`, which loads `.env` (real shell
variables win over it) and exits with a clear message if something required is
missing. Several reseller accounts: one env file each, picked per run with
`DOTENV_PATH=.env.shock-2 php sync_whm_accounts.php`.

Requires PHP 8.1+ with the curl, pdo_sqlite and soap extensions.

## Where things go

| What | Where |
|---|---|
| CSV / JSONL reports | `output/` (override with `OUTPUT_DIR`) |
| Fleet database | `websites.sqlite` (override with `DB_PATH`) |
| Credentials | `.env` (gitignored) |

Source labels contain `:` (e.g. `gentic.au:genticau`); in report filenames it
becomes `_`. The label stored in the DB is unchanged.

## Pipeline order

1. `sync_synergy_domains.php` - domains from Synergy Wholesale
2. `sync_whm_accounts.php` - accounts and domains (once per reseller). Fast: one API call per account
3. `reconcile_domains.php` - diff Synergy vs WHM -> `output/reconciliation_report.csv`
4. `whois_check_orphaned_whm.php` - WHOIS on orphans -> `output/whois_orphaned_whm_report.csv`
5. `check_wordpress_wptoolkit.php` (or `check_wordpress.php` as filesystem fallback) -> `output/wp_toolkit_report_*.csv`
6. `detect_new_installs.php` - flags new accounts/installs since last run

Not part of the pipeline, run when you want it: `sync_php_versions.php` (per-vhost PHP versions, one
extra API call per account, reads the accounts `sync_whm_accounts.php` stored).

## Other tools

| Script | Purpose |
|---|---|
| `check_error_logs.php` | error_log sizes/tails per account -> `output/error_log_report_*.csv` |
| `find_plugin_installs.php <slug>` | where a plugin is installed -> `output/plugin_report_<slug>_*.csv` |
| `upload_plugin.php` | deploy or remove `plugins/gg-user-protect.php` across WordPress installs (`--dry-run` recommended first) |
| `find_missing_mailchannels_records.php` | zones missing `_mailchannels` TXT -> `output/missing_mailchannels.csv` |
| `manage_dns_zone_records.php` | list/add/edit/remove zone records, `bulk-add-csv` (finds files in `output/` by bare name) |
| `sync_php_versions.php` | PHP version per vhost -> `whm_vhost_php_versions` and `whm_accounts.php_version`. `--user=a,b`, `--limit=N` |
| `deploy_plugin.php` | upload single-file plugins from `plugins/` to every WordPress install on a reseller. See below |
| `upgrade_php_versions.php` | move old ea-php vhosts to a target version. **Dry run unless `--apply`** |
| `check_autossl_exclusions.php` | domains excluded from AutoSSL -> `output/autossl_exclusions_*.csv` |

`upgrade_php_versions.php` and `check_autossl_exclusions.php` replace the old
Node scripts (`index.js`, `target.js`, `ssl.js`); they use the shared helpers in
`lib/whm.php`.

## Deploying plugins

`plugins/` holds the single-file plugins we push fleet-wide:

| File | What it does |
|---|---|
| `gg-user-enumeration-protection.php` | blocks `?author=N`, author archives, REST users, users sitemap, oEmbed author data, account-revealing login / lost-password messages |
| `gg-litespeed-nocache.php` | never caches pages with a Gravity Form or password-protected/private content, forces the LiteSpeed public TTL to 8 hours |

`deploy_plugin.php` uploads them to `wp-content/mu-plugins/` (default) or `wp-content/plugins/`
on every WordPress docroot of one reseller (one source per run, like the other scripts):

```bash
php deploy_plugin.php --all --dry-run                                  # look first
php deploy_plugin.php --file=gg-user-enumeration-protection.php --user=someacct --verify
php deploy_plugin.php --all --limit=5 --verify                         # small batch
DOTENV_PATH=.env.shock-2 php deploy_plugin.php --all --verify          # other reseller
php deploy_plugin.php --file=my-plugin.php --target=plugins            # normal plugins folder
php deploy_plugin.php --file=gg-litespeed-nocache.php --remove         # roll back
```

mu-plugins load automatically; a file uploaded to `plugins/` still has to be activated.
Identical files are left alone, so re-running is safe. `--verify` (mu-plugins only) requests each
site afterwards: 5xx means that site is broken and the file should be removed. Report:
`output/plugin-deploy-<source>-<target>-<timestamp>.csv`.

Keep everything in `plugins/` PHP 5.6 compatible - a parse error in a mu-plugin white-screens the site.
