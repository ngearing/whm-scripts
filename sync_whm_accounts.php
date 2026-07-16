<?php

/**
 * sync_whm_accounts.php
 *
 * Pulls the full list of cPanel accounts from your Shock Hosting WHM
 * reseller account (whmapi1 `listaccts`), plus the PHP version assigned to
 * each vhost - fetched per-account via the `uapi_cpanel` proxy running the
 * LangPHP::php_get_vhost_versions UAPI function as that cPanel user, since
 * reseller ACLs typically don't grant the top-level whmapi1 PHP functions.
 * Both are stored into the same local SQLite database used by
 * sync_synergy_domains.php.
 *
 * This is step 2 of the reconciliation pipeline: "Pull domains from Shock
 * and match up cPanel installs". Once both sync scripts have run, a third
 * reconcile script diffs synergy_domains against whm_accounts to flag
 * orphans in either direction.
 *
 * Usage:
 *   php sync_whm_accounts.php
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

// Same DB file the Synergy sync writes to, so both tables live together.
define('DB_PATH', __DIR__ . '/websites.sqlite');

// ---------------------------------------------------------------------
// DB SETUP
// ---------------------------------------------------------------------
function get_db(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whm_accounts (
            username         TEXT PRIMARY KEY,
            domain           TEXT,
            plan             TEXT,
            ip               TEXT,
            disklimit         TEXT,
            diskused          TEXT,
            suspended         INTEGER,
            suspend_reason    TEXT,
            php_version       TEXT,
            last_synced_at    TEXT
        )
    ");

    // Separate table since php_get_vhost_versions reports per-vhost, and a
    // single cPanel account can have multiple vhosts (addon domains) each
    // running a different PHP version.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whm_vhost_php_versions (
            vhost            TEXT PRIMARY KEY,
            account          TEXT,
            php_version      TEXT,
            last_synced_at   TEXT
        )
    ");

    return $pdo;
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
    echo "Fetching cPanel account list (listaccts)...\n";

    $response = whm_api_call('listaccts');

    $result = $response['metadata']['result'] ?? 0;
    if ($result != 1) {
        $reason = $response['metadata']['reason'] ?? 'Unknown error';
        throw new RuntimeException("listaccts failed: {$reason}");
    }

    $accounts = $response['data']['acct'] ?? [];
    echo "Received " . count($accounts) . " accounts.\n";

    $upsert = $pdo->prepare("
        INSERT INTO whm_accounts
            (username, domain, plan, ip, disklimit, diskused,
             suspended, suspend_reason, last_synced_at)
        VALUES
            (:username, :domain, :plan, :ip, :disklimit, :diskused,
             :suspended, :suspend_reason, :last_synced_at)
        ON CONFLICT(username) DO UPDATE SET
            domain          = excluded.domain,
            plan            = excluded.plan,
            ip              = excluded.ip,
            disklimit       = excluded.disklimit,
            diskused        = excluded.diskused,
            suspended       = excluded.suspended,
            suspend_reason  = excluded.suspend_reason,
            last_synced_at  = excluded.last_synced_at
    ");

    $pdo->beginTransaction();
    foreach ($accounts as $acct) {
        $upsert->execute([
            ':username'       => $acct['user'] ?? null,
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
 * @param array $accounts The account rows returned from listaccts (needs
 *                         'user' and 'domain' keys per entry)
 */
function sync_php_versions(PDO $pdo, string $syncedAt, array $accounts): int {
    echo "Fetching PHP version per account via uapi_cpanel (this loops one call per account)...\n";

    $upsertVhost = $pdo->prepare("
        INSERT INTO whm_vhost_php_versions (vhost, account, php_version, last_synced_at)
        VALUES (:vhost, :account, :php_version, :last_synced_at)
        ON CONFLICT(vhost) DO UPDATE SET
            account         = excluded.account,
            php_version     = excluded.php_version,
            last_synced_at  = excluded.last_synced_at
    ");

    $updateAcctPhp = $pdo->prepare("
        UPDATE whm_accounts SET php_version = :php_version
        WHERE username = :username
    ");

    $totalVhosts = 0;
    $errorCount = 0;
    $i = 0;

    foreach ($accounts as $acct) {
        $i++;
        $username = $acct['user'] ?? null;
        $domain = $acct['domain'] ?? null;

        if ($username === null || $domain === null) {
            continue;
        }

        try {
            $vhostList = get_php_versions_for_account($username, $domain);
        } catch (Throwable $e) {
            fwrite(STDERR, "  [{$username}] PHP version lookup failed: " . $e->getMessage() . "\n");
            $errorCount++;
            continue;
        }

        // Match the primary domain's vhost entry; fall back to the first
        // entry if there's no exact match (mirrors the Node.js logic).
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

        $pdo->beginTransaction();
        foreach ($vhostList as $v) {
            $vhost = $v['vhost'] ?? null;
            $version = $v['version'] ?? null;
            if ($vhost === null) {
                continue;
            }

            $upsertVhost->execute([
                ':vhost'          => $vhost,
                ':account'        => $username,
                ':php_version'    => $version,
                ':last_synced_at' => $syncedAt,
            ]);
            $totalVhosts++;
        }

        $updateAcctPhp->execute([
            ':php_version' => $primaryVersion,
            ':username'    => $username,
        ]);
        $pdo->commit();

        if ($i % 25 === 0) {
            echo "  ...processed {$i}/" . count($accounts) . " accounts\n";
        }

        // Be polite to the API - 400+ sequential calls without a pause can
        // trip rate limiting on shared reseller infrastructure.
        usleep(150000); // 0.15s
    }

    echo "PHP version sync complete. {$totalVhosts} vhost records synced, {$errorCount} accounts failed lookup.\n";

    return $totalVhosts;
}

// ---------------------------------------------------------------------
// RUN
// ---------------------------------------------------------------------
try {
    $pdo = get_db();
    $syncedAt = date('c');

    $accounts = sync_whm_accounts($pdo, $syncedAt);
    $phpCount = sync_php_versions($pdo, $syncedAt, $accounts);

    echo "\nDone.\n";
    echo "  Accounts synced: " . count($accounts) . "\n";
    echo "  PHP version records synced: {$phpCount}\n";
    echo "Data stored in " . DB_PATH . " (tables: whm_accounts, whm_vhost_php_versions)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
