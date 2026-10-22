<?php

/**
 * sync_whm_accounts.php
 *
 * Pulls the full list of cPanel accounts from your Shock Hosting WHM
 * reseller account (whmapi1 `listaccts`), plus - per account, via the
 * `uapi_cpanel` proxy running UAPI functions as that cPanel user (since
 * reseller ACLs typically don't grant the top-level whmapi1 equivalents) -
 * the full domain inventory: main, addon, and parked domains
 * (DomainInfo::list_domains). Both are stored into the same local SQLite
 * database used by sync_synergy_domains.php.
 *
 * PHP versions are NOT fetched here any more - that was a second API call
 * per account and made this sync twice as slow, and nothing in the
 * reconciliation pipeline needs it. Run sync_php_versions.php when you want
 * them. Whatever it stored previously (whm_accounts.php_version and
 * whm_vhost_php_versions) is left in place by this script, apart from rows
 * for accounts that no longer exist.
 *
 * This is step 2 of the reconciliation pipeline: "Pull domains from Shock
 * and match up cPanel installs". Once both sync scripts have run, a third
 * reconcile script diffs synergy_domains against whm_accounts to flag
 * orphans in either direction.
 *
 * Usage:
 *   WHM_SOURCE_LABEL=shock-1 WHM_HOST=... WHM_USERNAME=... WHM_API_TOKEN=... php sync_whm_accounts.php
 *   WHM_SOURCE_LABEL=shock-2 WHM_HOST=... WHM_USERNAME=... WHM_API_TOKEN=... php sync_whm_accounts.php
 *
 * Run it once per Shock reseller account, each time with a distinct
 * WHM_SOURCE_LABEL. Every row is tagged with that label, and each run only
 * clears/rebuilds its own source's rows - so syncing account 2 never
 * touches account 1's data, even if both happen to reuse the same cPanel
 * username somewhere.
 *
 * Config is read from real environment variables first, falling back to a
 * .env file sitting next to this script (see bootstrap.php) - so you
 * can either export WHM_SOURCE_LABEL etc. per invocation as shown above,
 * or set the shared credentials in .env and just override
 * WHM_SOURCE_LABEL per run.
 *
 * Requires:
 *   - PHP cURL extension (bundled with Homebrew's php formula)
 *   - PHP PDO SQLite extension (also bundled)
 *   - A WHM API token generated under WHM -> Development -> Manage API
 *     Tokens on your Shock reseller account, scoped at minimum to the
 *     `listaccts` and `uapi_cpanel` ACLs (or full access)
 */

// ---------------------------------------------------------------------
// CONFIG - loaded by bootstrap.php from the environment / .env file, so
// credentials never end up committed to source control.
//
//   WHM_HOST          your reseller server hostname, e.g. server3.shockhosting.com
//   WHM_PORT          defaults to 2087 (HTTPS API)
//   WHM_USERNAME      the reseller username that owns the token (not root)
//   WHM_API_TOKEN     WHM -> Development -> Manage API Tokens
//   WHM_SOURCE_LABEL  tags every record with the reseller account it came
//                     from, so a username that exists on two accounts can't
//                     overwrite the other's data on re-sync. Set it per run
//                     (shock-1, shock-2); defaults to "host:username".
// ---------------------------------------------------------------------
require __DIR__ . '/bootstrap.php';
define_whm_config();
require __DIR__ . '/lib/whm.php'; // whm_api_call()

// ---------------------------------------------------------------------
// DB SETUP
// ---------------------------------------------------------------------
/**
 * If you ran an earlier version of this script before multi-account
 * support existed, whm_accounts/whm_vhost_php_versions/whm_domains won't
 * have a whm_source column yet. Since these tables are just a synced cache
 * (fully rebuilt by re-running the sync scripts), the simplest safe fix is
 * to drop and let them get recreated with the new schema, rather than
 * attempting a fragile in-place ALTER. This only touches the three WHM
 * tables - synergy_domains and any reconciliation output are untouched.
 */
function migrate_legacy_schema(PDO $pdo): void {
    $tables = ['whm_accounts', 'whm_vhost_php_versions', 'whm_domains'];

    foreach ($tables as $table) {
        $exists = $pdo->query("
            SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'
        ")->fetchColumn();

        if (!$exists) {
            continue;
        }

        $columns = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
        $hasSourceColumn = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'whm_source') {
                $hasSourceColumn = true;
                break;
            }
        }

        if (!$hasSourceColumn) {
            echo "Migrating {$table} to multi-account schema (old data will be re-synced fresh)...\n";
            $pdo->exec("DROP TABLE {$table}");
        }
    }
}

function get_db(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    migrate_legacy_schema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whm_accounts (
            username         TEXT,
            whm_source       TEXT,
            domain           TEXT,
            plan             TEXT,
            ip               TEXT,
            disklimit         TEXT,
            diskused          TEXT,
            suspended         INTEGER,
            suspend_reason    TEXT,
            php_version       TEXT,
            last_synced_at    TEXT,
            PRIMARY KEY (username, whm_source)
        )
    ");

    // Separate table since php_get_vhost_versions reports per-vhost, and a
    // single cPanel account can have multiple vhosts (addon domains) each
    // running a different PHP version. Filled by sync_php_versions.php; it is
    // created here so every script that opens the DB sees the same schema.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whm_vhost_php_versions (
            vhost            TEXT,
            whm_source       TEXT,
            account          TEXT,
            php_version      TEXT,
            last_synced_at   TEXT,
            PRIMARY KEY (vhost, whm_source)
        )
    ");

    // listaccts only returns each account's primary domain. This table
    // captures the FULL domain inventory per account (main, addon, parked)
    // via DomainInfo::list_domains, which is what lets us match Synergy
    // domains against addon/parked domains, not just primary installs.
    //
    // Note: domain is intentionally NOT unique on its own here - the same
    // domain string showing up under two different whm_source values would
    // mean the exact same domain is hosted on both reseller accounts,
    // which is itself worth surfacing as a data problem rather than
    // silently picking a winner. reconcile_domains.php flags this case.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whm_domains (
            domain           TEXT,
            whm_source       TEXT,
            account          TEXT,
            domain_type      TEXT,   -- 'main', 'addon', or 'parked'
            last_synced_at   TEXT,
            PRIMARY KEY (domain, whm_source)
        )
    ");

    return $pdo;
}

/**
 * Wipes only this WHM source's prior whm_domains rows, so a re-sync of one
 * reseller account never touches the other's data and correctly drops
 * domains that were removed since the last sync of THIS source.
 *
 * whm_accounts is upserted instead (see sync_whm_accounts()) so the
 * php_version that sync_php_versions.php stored survives, and
 * whm_vhost_php_versions is owned by sync_php_versions.php entirely.
 */
function clear_existing_source_domains(PDO $pdo, string $source): void {
    $pdo->prepare("DELETE FROM whm_domains WHERE whm_source = :source")
        ->execute([':source' => $source]);
}

// ---------------------------------------------------------------------
// SYNC: ACCOUNTS
// ---------------------------------------------------------------------
function sync_whm_accounts(PDO $pdo, string $syncedAt): array {
    echo "Fetching cPanel account list (listaccts) for source '" . WHM_SOURCE_LABEL . "'...\n";

    $response = whm_api_call('listaccts');

    $result = $response['metadata']['result'] ?? 0;
    if ($result != 1) {
        $reason = $response['metadata']['reason'] ?? 'Unknown error';
        throw new RuntimeException("listaccts failed: {$reason}");
    }

    $accounts = $response['data']['acct'] ?? [];
    echo "Received " . count($accounts) . " accounts.\n";

    // Upsert rather than delete+insert, so php_version (filled in separately
    // by sync_php_versions.php) survives. It is reset only if the account's
    // primary domain changed, since the stored version belonged to the old one.
    $upsert = $pdo->prepare("
        INSERT INTO whm_accounts
            (username, whm_source, domain, plan, ip, disklimit, diskused,
             suspended, suspend_reason, last_synced_at)
        VALUES
            (:username, :whm_source, :domain, :plan, :ip, :disklimit, :diskused,
             :suspended, :suspend_reason, :last_synced_at)
        ON CONFLICT (username, whm_source) DO UPDATE SET
            php_version    = CASE WHEN whm_accounts.domain IS excluded.domain
                                  THEN whm_accounts.php_version ELSE NULL END,
            domain         = excluded.domain,
            plan           = excluded.plan,
            ip             = excluded.ip,
            disklimit      = excluded.disklimit,
            diskused       = excluded.diskused,
            suspended      = excluded.suspended,
            suspend_reason = excluded.suspend_reason,
            last_synced_at = excluded.last_synced_at
    ");

    $pdo->beginTransaction();
    foreach ($accounts as $acct) {
        $upsert->execute([
            ':username'       => $acct['user'] ?? null,
            ':whm_source'     => WHM_SOURCE_LABEL,
            ':domain'         => $acct['domain'] ?? null,
            ':plan'           => $acct['plan'] ?? null,
            ':ip'             => $acct['ip'] ?? null,
            ':disklimit'      => $acct['disklimit'] ?? null,
            ':diskused'       => $acct['diskused'] ?? null,
            ':suspended'      => !empty($acct['suspended']) ? 1 : 0,
            ':suspend_reason' => $acct['suspendreason'] ?? null,
            ':last_synced_at' => $syncedAt,
        ]);
    }

    // Every account still on the reseller was just stamped with $syncedAt, so
    // anything from this source with an older stamp has been removed.
    $pdo->prepare("DELETE FROM whm_accounts WHERE whm_source = :source AND last_synced_at <> :t")
        ->execute([':source' => WHM_SOURCE_LABEL, ':t' => $syncedAt]);

    // ...and drop stored PHP versions that belonged to those removed accounts.
    $pdo->prepare("
        DELETE FROM whm_vhost_php_versions
        WHERE whm_source = :source
          AND account NOT IN (SELECT username FROM whm_accounts WHERE whm_source = :source2)
    ")->execute([':source' => WHM_SOURCE_LABEL, ':source2' => WHM_SOURCE_LABEL]);
    $pdo->commit();

    return $accounts;
}

// ---------------------------------------------------------------------
// SYNC: DOMAIN INVENTORY PER ACCOUNT
// ---------------------------------------------------------------------
/**
 * On reseller-scoped WHM accounts the top-level whmapi1 equivalents are
 * often outside the reseller's ACL. The reliable path is to proxy a UAPI
 * call through WHM as the specific cPanel user via the `uapi_cpanel`
 * function - this borrows that cPanel user's own permissions.
 *
 * Because this call is scoped to one cPanel user at a time, it has to run
 * once per account rather than as a single bulk call - roughly one HTTP
 * round trip per site.
 */
/**
 * Fetches the full domain inventory (main, addon, parked) for one cPanel
 * account via DomainInfo::list_domains, proxied through uapi_cpanel the
 * same way as the other per-account lookups.
 *
 * @return array{main: ?string, addon: string[], parked: string[]}
 */
function get_domains_for_account(string $username): array {
    $params = [
        'cpanel.user'     => $username,
        'cpanel.module'   => 'DomainInfo',
        'cpanel.function' => 'list_domains',
    ];

    $response = whm_api_call('uapi_cpanel', $params);
    $data = $response['data']['uapi']['data'] ?? [];

    $addon = $data['addon_domains'] ?? [];
    $parked = $data['parked_domains'] ?? [];

    return [
        'main'   => $data['main_domain'] ?? null,
        'addon'  => is_array($addon) ? $addon : [],
        'parked' => is_array($parked) ? $parked : [],
    ];
}

/**
 * @param array $accounts The account rows returned from listaccts (needs
 *                         'user' and 'domain' keys per entry)
 */
function sync_account_domains(PDO $pdo, string $syncedAt, array $accounts): array {
    echo "Fetching full domain inventory per account (uapi_cpanel)...\n";

    clear_existing_source_domains($pdo, WHM_SOURCE_LABEL);

    $insertDomain = $pdo->prepare("
        INSERT INTO whm_domains (domain, whm_source, account, domain_type, last_synced_at)
        VALUES (:domain, :whm_source, :account, :domain_type, :last_synced_at)
    ");

    $totalDomains = 0;
    $errorCount = 0;
    $i = 0;

    foreach ($accounts as $acct) {
        $i++;
        $username = $acct['user'] ?? null;
        $domain = $acct['domain'] ?? null;

        if ($username === null || $domain === null) {
            continue;
        }

        // --- Domain inventory (main / addon / parked) ---
        try {
            $domainInfo = get_domains_for_account($username);
        } catch (Throwable $e) {
            fwrite(STDERR, "  [{$username}] Domain list lookup failed: " . $e->getMessage() . "\n");
            $domainInfo = ['main' => $domain, 'addon' => [], 'parked' => []];
            $errorCount++;
        }

        $mainDomain = $domainInfo['main'] ?? $domain;
        $domainsToInsert = [];
        if ($mainDomain) {
            $domainsToInsert[] = [$mainDomain, 'main'];
        }
        foreach ($domainInfo['addon'] as $addonDomain) {
            $domainsToInsert[] = [$addonDomain, 'addon'];
        }
        foreach ($domainInfo['parked'] as $parkedDomain) {
            $domainsToInsert[] = [$parkedDomain, 'parked'];
        }

        $pdo->beginTransaction();
        foreach ($domainsToInsert as [$domainName, $domainType]) {
            try {
                $insertDomain->execute([
                    ':domain'         => $domainName,
                    ':whm_source'     => WHM_SOURCE_LABEL,
                    ':account'        => $username,
                    ':domain_type'    => $domainType,
                    ':last_synced_at' => $syncedAt,
                ]);
                $totalDomains++;
            } catch (PDOException $e) {
                // Same domain claimed twice within this one source - flag it
                // rather than silently dropping or overwriting, since two
                // accounts on the same reseller both claiming the same
                // domain is a genuine account hygiene problem.
                fwrite(STDERR, "  WARNING: domain '{$domainName}' already claimed by another account in source '" . WHM_SOURCE_LABEL . "' - skipping duplicate from '{$username}'\n");
            }
        }
        $pdo->commit();

        if ($i % 25 === 0) {
            echo "  ...processed {$i}/" . count($accounts) . " accounts\n";
        }

        // Be polite to the API - hundreds of sequential calls without a
        // pause can trip rate limiting on shared reseller infrastructure.
        usleep(150000); // 0.15s
    }

    echo "Domain sync complete. {$totalDomains} domain records (main+addon+parked), {$errorCount} lookups failed.\n";

    return ['domains' => $totalDomains, 'errors' => $errorCount];
}

// ---------------------------------------------------------------------
// RUN
// ---------------------------------------------------------------------
try {
    $pdo = get_db();
    $syncedAt = date('c');

    $accounts = sync_whm_accounts($pdo, $syncedAt);
    $details = sync_account_domains($pdo, $syncedAt, $accounts);

    echo "\nDone. (source: " . WHM_SOURCE_LABEL . ")\n";
    echo "  Accounts synced: " . count($accounts) . "\n";
    echo "  Domain records synced (main+addon+parked): {$details['domains']}\n";
    echo "Data stored in " . DB_PATH . " (tables: whm_accounts, whm_domains)\n";
    echo "PHP versions are not synced here - run sync_php_versions.php when you need them.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
