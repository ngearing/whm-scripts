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
2. `sync_whm_accounts.php` - accounts, PHP versions, domains (once per reseller)
3. `reconcile_domains.php` - diff Synergy vs WHM -> `output/reconciliation_report.csv`
4. `whois_check_orphaned_whm.php` - WHOIS on orphans -> `output/whois_orphaned_whm_report.csv`
5. `check_wordpress_wptoolkit.php` (or `check_wordpress.php` as filesystem fallback) -> `output/wp_toolkit_report_*.csv`
6. `detect_new_installs.php` - flags new accounts/installs since last run

## Other tools

| Script | Purpose |
|---|---|
| `check_error_logs.php` | error_log sizes/tails per account -> `output/error_log_report_*.csv` |
| `find_plugin_installs.php <slug>` | where a plugin is installed -> `output/plugin_report_<slug>_*.csv` |
| `find_missing_mailchannels_records.php` | zones missing `_mailchannels` TXT -> `output/missing_mailchannels.csv` |
| `manage_dns_zone_records.php` | list/add/edit/remove zone records, `bulk-add-csv` (finds files in `output/` by bare name) |
| `upgrade_php_versions.php` | move old ea-php vhosts to a target version. **Dry run unless `--apply`** |
| `check_autossl_exclusions.php` | domains excluded from AutoSSL -> `output/autossl_exclusions_*.csv` |

`upgrade_php_versions.php` and `check_autossl_exclusions.php` replace the old
Node scripts (`index.js`, `target.js`, `ssl.js`); they use the shared helpers in
`lib/whm.php`.
