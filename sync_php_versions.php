<?php

/**
 * sync_php_versions.php
 *
 * Pulls the PHP version of every vhost on a WHM reseller account
 * (LangPHP::php_get_vhost_versions, proxied through uapi_cpanel as each cPanel
 * user - reseller ACLs don't grant the top-level whmapi1 equivalent) and stores
 * it in websites.sqlite:
 *
 *   whm_vhost_php_versions   one row per vhost
 *   whm_accounts.php_version the account's primary-domain version
 *
 * This used to be part of sync_whm_accounts.php. It is a separate script now
 * because it costs one extra API call per account and nothing in the
 * reconciliation pipeline needs it - run it when you want a PHP report, not on
 * every sync.
 *
 * Needs sync_whm_accounts.php to have run first for this source (it reads the
 * account list from the DB rather than asking WHM again).
 *
 * Usage:
 *   php sync_php_versions.php
 *   php sync_php_versions.php --user=cpuser,other   # only these accounts
 *   php sync_php_versions.php --limit=10            # first 10 accounts
 *   php sync_php_versions.php --delay=0             # ms between accounts (default 150)
 *
 * A failed lookup leaves that account's previously stored versions untouched.
 * Suspended accounts are skipped (UAPI calls against them just fail).
 *
 * Credentials come from .env / the environment (see bootstrap.php).
 */

require __DIR__ . '/bootstrap.php';
define_whm_config();
require __DIR__ . '/lib/whm.php';

function open_fleet_db(): PDO {
    if (!file_exists(DB_PATH)) {
        throw new RuntimeException('Fleet DB not found at ' . DB_PATH . ' - run sync_whm_accounts.php first.');
    }
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    foreach (['whm_accounts', 'whm_vhost_php_versions'] as $table) {
        $found = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetchColumn();
        if (!$found) {
            throw new RuntimeException("Table {$table} is missing - run sync_whm_accounts.php first.");
        }
    }
    return $pdo;
}

/**
 * @return array<int, array{vhost:string, version:?string}>
 * @throws RuntimeException when the UAPI call itself reports failure, so the
 *         caller keeps the old data instead of overwriting it with nothing.
 */
function fetch_vhost_versions(string $username): array {
    $result = with_retries(fn () => uapi_call($username, 'LangPHP', 'php_get_vhost_versions'));

    if ((int) ($result['status'] ?? 0) !== 1) {
        $errors = array_filter((array) ($result['errors'] ?? []));
        throw new RuntimeException($errors ? implode('; ', array_map('strval', $errors)) : 'unexpected UAPI response');
    }

    $list = $result['data'] ?? [];
    return is_array($list) ? $list : [];
}

/** The primary domain's vhost entry, else the first one, else 'Not Defined'. */
function primary_version(array $vhostList, string $primaryDomain): string {
    $entry = null;
    foreach ($vhostList as $v) {
        if (($v['vhost'] ?? null) === $primaryDomain) {
            $entry = $v;
            break;
        }
    }
    if ($entry === null && $vhostList) {
        $entry = $vhostList[0];
    }
    return $entry['version'] ?? 'Not Defined';
}

function run(array $args): int {
    $onlyUsers = isset($args['user']) && is_string($args['user'])
        ? array_filter(array_map('trim', explode(',', $args['user'])))
        : [];
    $limit   = isset($args['limit']) ? (int) $args['limit'] : 0;
    $delayMs = isset($args['delay']) ? max(0, (int) $args['delay']) : 150;

    $pdo = open_fleet_db();

    $stmt = $pdo->prepare('SELECT username, domain, suspended FROM whm_accounts WHERE whm_source = :s ORDER BY username');
    $stmt->execute([':s' => WHM_SOURCE_LABEL]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$accounts) {
        fwrite(STDERR, "No accounts found for source '" . WHM_SOURCE_LABEL . "' in whm_accounts - run sync_whm_accounts.php for this source first.\n");
        return 1;
    }
    if ($onlyUsers) {
        $accounts = array_values(array_filter($accounts, fn ($a) => in_array($a['username'], $onlyUsers, true)));
        if (!$accounts) {
            fwrite(STDERR, "None of the requested users exist for source '" . WHM_SOURCE_LABEL . "'.\n");
            return 1;
        }
    }

    $syncedAt = date('c');
    $deleteVhosts = $pdo->prepare('DELETE FROM whm_vhost_php_versions WHERE account = :a AND whm_source = :s');
    $insertVhost  = $pdo->prepare('
        INSERT INTO whm_vhost_php_versions (vhost, whm_source, account, php_version, last_synced_at)
        VALUES (:vhost, :s, :account, :version, :t)
    ');
    $updateAcct   = $pdo->prepare('UPDATE whm_accounts SET php_version = :v WHERE username = :u AND whm_source = :s');

    $total = count($accounts);
    echo "Fetching PHP versions for {$total} accounts on '" . WHM_SOURCE_LABEL . "'...\n";

    $done = $skipped = $errors = $vhostRows = 0;
    foreach ($accounts as $n => $acct) {
        if ($limit > 0 && $done + $errors >= $limit) {
            break;
        }
        $username = $acct['username'];

        if (!empty($acct['suspended'])) {
            $skipped++;
            continue;
        }

        try {
            $vhostList = fetch_vhost_versions($username);
        } catch (Throwable $e) {
            fwrite(STDERR, "  [{$username}] PHP version lookup failed: " . $e->getMessage() . "\n");
            $errors++;
            continue;
        }

        $pdo->beginTransaction();
        $deleteVhosts->execute([':a' => $username, ':s' => WHM_SOURCE_LABEL]);
        foreach ($vhostList as $v) {
            $vhost = $v['vhost'] ?? null;
            if ($vhost === null) {
                continue;
            }
            try {
                $insertVhost->execute([
                    ':vhost'   => $vhost,
                    ':s'       => WHM_SOURCE_LABEL,
                    ':account' => $username,
                    ':version' => $v['version'] ?? null,
                    ':t'       => $syncedAt,
                ]);
                $vhostRows++;
            } catch (PDOException $e) {
                // Same vhost claimed by two accounts on one reseller - a real
                // hygiene problem, so say so rather than silently picking one.
                fwrite(STDERR, "  WARNING: vhost '{$vhost}' already claimed by another account in source '" . WHM_SOURCE_LABEL . "' - skipping duplicate from '{$username}'\n");
            }
        }
        $updateAcct->execute([
            ':v' => primary_version($vhostList, (string) $acct['domain']),
            ':u' => $username,
            ':s' => WHM_SOURCE_LABEL,
        ]);
        $pdo->commit();
        $done++;

        if (($n + 1) % 25 === 0) {
            echo '  ...processed ' . ($n + 1) . "/{$total} accounts\n";
        }
        if ($delayMs > 0) {
            usleep($delayMs * 1000); // be polite to shared reseller infrastructure
        }
    }

    echo "\nDone. (source: " . WHM_SOURCE_LABEL . ")\n";
    echo "  Accounts updated: {$done}\n";
    echo "  Vhost PHP records: {$vhostRows}\n";
    echo "  Suspended, skipped: {$skipped}\n";
    echo "  Lookups failed (old data kept): {$errors}\n";
    return 0;
}

try {
    exit(run(cli_args($argv)));
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
