<?php

/**
 * sync_whm_accounts.php
 *
 * Pulls the full list of cPanel accounts from your Shock Hosting WHM
 * reseller account (whmapi1 `listaccts`), plus - per account, via the
 * `uapi_cpanel` proxy running UAPI functions as that cPanel user (since
 * reseller ACLs typically don't grant the top-level whmapi1 equivalents) -
 * the PHP version per vhost (LangPHP::php_get_vhost_versions) and the full
 * domain inventory: main, addon, and parked domains (DomainInfo::list_domains).
 * All three are stored into the same local SQLite database used by
 * sync_synergy_domains.php.
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
 * Requires:
 *   - PHP cURL extension (bundled with Homebrew's php formula)
 *   - PHP PDO SQLite extension (also bundled)
 *   - A WHM API token generated under WHM -> Development -> Manage API
 *     Tokens on your Shock reseller account, scoped at minimum to the
 *     `listaccts` and `uapi_cpanel` ACLs (or full access)
 */

// ---------------------------------------------------------------------
// CONFIG - fill these in, or load from environment variables so
// credentials never end up committed to source control.
// ---------------------------------------------------------------------

// The hostname of your Shock Hosting reseller server, e.g. server3.shockhosting.com
// (find this in your Shock welcome email / WHM login URL).
define('WHM_HOST', getenv('WHM_HOST') ?: 'YOUR_SERVER_HOSTNAME');

// WHM listens on 2087 for HTTPS API access.
define('WHM_PORT', getenv('WHM_PORT') ?: '2087');

// The WHM user that owns the API token - for a reseller account this is
// your reseller username, not "root" (root-level tokens aren't available
// to reseller-only accounts on shared reseller hosting like Shock).
define('WHM_USERNAME', getenv('WHM_USERNAME') ?: 'YOUR_WHM_USERNAME');

define('WHM_API_TOKEN', getenv('WHM_API_TOKEN') ?: 'YOUR_API_TOKEN');

// You have more than one Shock reseller account (too many cPanels to fit
// under one), so every record needs to be tagged with which account it
// came from - otherwise a username that happens to exist on both accounts
// would silently overwrite the other's data on re-sync.
//
// Set this explicitly per run, e.g. WHM_SOURCE_LABEL=shock-1 and
// WHM_SOURCE_LABEL=shock-2. If left unset it falls back to
// "host:username", which is unique enough by default but a plain label is
// easier to read in reports.
define('WHM_SOURCE_LABEL', getenv('WHM_SOURCE_LABEL') ?: (WHM_HOST . ':' . WHM_USERNAME));

// Same DB file the Synergy sync writes to, so both tables live together.
define('DB_PATH', __DIR__ . '/websites.sqlite');

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
    // running a different PHP version.
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
 * Wipes only this WHM source's prior rows from all three tables, so a
 * re-sync of one reseller account never touches the other's data and
 * correctly drops accounts/domains that were removed since the last sync
 * of THIS source.
 */
function clear_existing_source_data(PDO $pdo, string $source): void {
    $pdo->prepare("DELETE FROM whm_accounts WHERE whm_source = :source")
        ->execute([':source' => $source]);
    $pdo->prepare("DELETE FROM whm_vhost_php_versions WHERE whm_source = :source")
        ->execute([':source' => $source]);
    $pdo->prepare("DELETE FROM whm_domains WHERE whm_source = :source")
        ->execute([':source' => $source]);
}

// ---------------------------------------------------------------------
// WHM API HELPER
// ---------------------------------------------------------------------
/**
 * Calls a whmapi1 function over HTTPS and returns the decoded JSON body.
 *
 * @param string $function WHM API 1 function name, e.g. 'listaccts'
 * @param array  $params   Extra query parameters for the call
 * @return array Decoded JSON response
 * @throws RuntimeException on transport or HTTP-level failure
 */
function whm_api_call(string $function, array $params = []): array {
    $params['api.version'] = 1;
    $url = sprintf(
        'https://%s:%s/json-api/%s?%s',
        WHM_HOST,
        WHM_PORT,
        $function,
        http_build_query($params)
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: whm ' . WHM_USERNAME . ':' . WHM_API_TOKEN,
        ],
        CURLOPT_TIMEOUT        => 60,
        // Shock's WHM servers use valid certs, so we leave SSL verification
        // on. If you're testing against a self-signed dev box, this is the
        // (not recommended) knob to flip off temporarily.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("cURL error calling {$function}: {$curlError}");
    }

    if ($httpCode !== 200) {
        throw new RuntimeException("HTTP {$httpCode} calling {$function}: " . substr($body, 0, 300));
    }

    $decoded = json_decode($body, true);
    if ($decoded === null) {
        throw new RuntimeException("Failed to decode JSON response from {$function}");
    }

    return $decoded;
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

    // Only clear this source's own prior rows - the other reseller
    // account's data (if already synced) is untouched.
    clear_existing_source_data($pdo, WHM_SOURCE_LABEL);

    $insert = $pdo->prepare("
        INSERT INTO whm_accounts
            (username, whm_source, domain, plan, ip, disklimit, diskused,
             suspended, suspend_reason, last_synced_at)
        VALUES
            (:username, :whm_source, :domain, :plan, :ip, :disklimit, :diskused,
             :suspended, :suspend_reason, :last_synced_at)
    ");

    $pdo->beginTransaction();
    foreach ($accounts as $acct) {
        $insert->execute([
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
    $pdo->commit();

    return $accounts;
}

// ---------------------------------------------------------------------
// SYNC: PHP VERSIONS PER VHOST
// ---------------------------------------------------------------------
/**
 * On reseller-scoped WHM accounts (like Shock's reseller plans), the
 * top-level `whmapi1 php_get_vhost_versions` function is often outside the
 * reseller's ACL. The reliable path is to proxy a UAPI call through WHM as
 * the specific cPanel user via the `uapi_cpanel` function - this borrows
 * that cPanel user's own permissions instead of requiring reseller-level
 * PHP management rights. This mirrors the working Node.js implementation.
 *
 * Because this call is scoped to one cPanel user at a time, it has to run
 * once per account rather than as a single bulk call - expect this to take
 * a while longer at 400+ accounts (roughly one HTTP round trip per site).
 */
function get_php_versions_for_account(string $username, string $primaryDomain): array {
    $params = [
        'cpanel.user'     => $username,
        'cpanel.module'   => 'LangPHP',
        'cpanel.function' => 'php_get_vhost_versions',
    ];

    $response = whm_api_call('uapi_cpanel', $params);

    $vhostList = $response['data']['uapi']['data'] ?? [];
    if (!is_array($vhostList)) {
        $vhostList = [];
    }

    return $vhostList; // array of ['vhost' => ..., 'version' => ...]
}

/**
 * Fetches the full domain inventory (main, addon, parked) for one cPanel
 * account via DomainInfo::list_domains, proxied through uapi_cpanel the
 * same way as the PHP version lookup above.
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
function sync_account_details(PDO $pdo, string $syncedAt, array $accounts): array {
    echo "Fetching PHP versions and full domain inventory per account (uapi_cpanel)...\n";
    echo "This loops two calls per account, so it's the slowest step - grab a coffee.\n";

    $insertVhost = $pdo->prepare("
        INSERT INTO whm_vhost_php_versions (vhost, whm_source, account, php_version, last_synced_at)
        VALUES (:vhost, :whm_source, :account, :php_version, :last_synced_at)
    ");

    $updateAcctPhp = $pdo->prepare("
        UPDATE whm_accounts SET php_version = :php_version
        WHERE username = :username AND whm_source = :whm_source
    ");

    $insertDomain = $pdo->prepare("
        INSERT INTO whm_domains (domain, whm_source, account, domain_type, last_synced_at)
        VALUES (:domain, :whm_source, :account, :domain_type, :last_synced_at)
    ");

    $totalVhosts = 0;
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

        // --- PHP versions ---
        try {
            $vhostList = get_php_versions_for_account($username, $domain);
        } catch (Throwable $e) {
            fwrite(STDERR, "  [{$username}] PHP version lookup failed: " . $e->getMessage() . "\n");
            $vhostList = [];
            $errorCount++;
        }

        $primaryVersion = 'Not Defined';
        $primaryVhostEntry = null;
        foreach ($vhostList as $v) {
            if (($v['vhost'] ?? null) === $domain) {
                $primaryVhostEntry = $v;
                break;
            }
        }
        if ($primaryVhostEntry === null && count($vhostList) > 0) {
            $primaryVhostEntry = $vhostList[0];
        }
        if ($primaryVhostEntry !== null) {
            $primaryVersion = $primaryVhostEntry['version'] ?? 'Not Defined';
        }

        // --- Domain inventory (main / addon / parked) ---
        try {
            $domainInfo = get_domains_for_account($username);
        } catch (Throwable $e) {
            fwrite(STDERR, "  [{$username}] Domain list lookup failed: " . $e->getMessage() . "\n");
            $domainInfo = ['main' => $domain, 'addon' => [], 'parked' => []];
            $errorCount++;
        }

        $pdo->beginTransaction();

        foreach ($vhostList as $v) {
            $vhost = $v['vhost'] ?? null;
            $version = $v['version'] ?? null;
            if ($vhost === null) {
                continue;
            }
            try {
                $insertVhost->execute([
                    ':vhost'          => $vhost,
                    ':whm_source'     => WHM_SOURCE_LABEL,
                    ':account'        => $username,
                    ':php_version'    => $version,
                    ':last_synced_at' => $syncedAt,
                ]);
                $totalVhosts++;
            } catch (PDOException $e) {
                // Same vhost claimed twice within this one source - flag it
                // rather than silently dropping or overwriting, since two
                // accounts on the same reseller both claiming the same
                // domain is a genuine account hygiene problem.
                fwrite(STDERR, "  WARNING: vhost '{$vhost}' already claimed by another account in source '" . WHM_SOURCE_LABEL . "' - skipping duplicate from '{$username}'\n");
            }
        }

        $updateAcctPhp->execute([
            ':php_version' => $primaryVersion,
            ':username'    => $username,
            ':whm_source'  => WHM_SOURCE_LABEL,
        ]);

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

    echo "Account detail sync complete. {$totalVhosts} vhost PHP records, "
        . "{$totalDomains} domain records (main+addon+parked), {$errorCount} lookups failed.\n";

    return ['vhosts' => $totalVhosts, 'domains' => $totalDomains, 'errors' => $errorCount];
}

// ---------------------------------------------------------------------
// RUN
// ---------------------------------------------------------------------
try {
    $pdo = get_db();
    $syncedAt = date('c');

    $accounts = sync_whm_accounts($pdo, $syncedAt);
    $details = sync_account_details($pdo, $syncedAt, $accounts);

    echo "\nDone. (source: " . WHM_SOURCE_LABEL . ")\n";
    echo "  Accounts synced: " . count($accounts) . "\n";
    echo "  PHP version records synced: {$details['vhosts']}\n";
    echo "  Domain records synced (main+addon+parked): {$details['domains']}\n";
    echo "Data stored in " . DB_PATH . " (tables: whm_accounts, whm_vhost_php_versions, whm_domains)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
