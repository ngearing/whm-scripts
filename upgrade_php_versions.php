<?php

/**
 * upgrade_php_versions.php
 *
 * Finds vhosts on a WHM reseller account that are running an older
 * EasyApache PHP version (ea-php56 ... ea-php82) and moves them up to a
 * target version (default ea-php83). Replaces the old Node index.js /
 * target.js.
 *
 * DRY RUN BY DEFAULT: without --apply it only reports what it would change.
 * Even in a dry run it makes read-only calls to the reseller (listaccts and
 * php_get_vhost_versions), so it works from the live state, not from a
 * possibly stale sync.
 *
 * Usage:
 *   php upgrade_php_versions.php                       # report only, all accounts
 *   php upgrade_php_versions.php --user=cpuser         # report one account
 *   php upgrade_php_versions.php --user=cpuser --apply # upgrade one account
 *   php upgrade_php_versions.php --apply               # upgrade everything outdated
 *
 * Options:
 *   --apply          actually change PHP versions (default: dry run)
 *   --user=NAME      only this cPanel account
 *   --primary-only   only each account's primary domain, leave addon domains alone
 *   --target=VER     target version, e.g. ea-php83 (or env PHP_TARGET_VERSION)
 *
 * Rules:
 *   - Only ea-phpNN versions LOWER than the target are changed. Anything at or
 *     above the target, and anything that isn't ea-phpNN (alt-php*, inherit),
 *     is left alone - so this can never downgrade a site.
 *   - Suspended accounts are skipped.
 *   - After each successful change it sends a HEAD request to the site and
 *     prints the HTTP status. A 5xx or no response is flagged in the summary
 *     with the previous version, so you know which sites to look at (roll
 *     back in WHM -> MultiPHP Manager).
 *   - Successful changes are also written back to websites.sqlite
 *     (whm_vhost_php_versions / whm_accounts) so reports stay in step until
 *     the next sync_php_versions.php run.
 *
 * Credentials come from .env / the environment (see bootstrap.php).
 */

require __DIR__ . '/bootstrap.php';
define_whm_config();
require __DIR__ . '/lib/whm.php';

/** 'ea-php74' => 74, anything else => null. */
function php_version_number(string $version): ?int {
    return preg_match('/^ea-php(\d{2})$/', $version, $m) ? (int) $m[1] : null;
}

/** @return array<int, array{vhost: string, version: string}> */
function get_vhost_versions(string $user): array {
    $res = with_retries(fn () => uapi_call($user, 'LangPHP', 'php_get_vhost_versions'));
    if (isset($res['status']) && (int) $res['status'] === 0) {
        throw new RuntimeException($res['errors'][0] ?? 'php_get_vhost_versions failed');
    }
    $data = $res['data'] ?? [];
    return is_array($data) ? $data : [];
}

function set_vhost_version(string $user, string $vhost, string $version): array {
    $res = with_retries(fn () => uapi_call($user, 'LangPHP', 'php_set_vhost_versions', [
        'vhost'   => $vhost,
        'version' => $version,
    ], true));

    $ok = (int) ($res['status'] ?? 0) === 1;
    return [$ok, $ok ? null : ($res['errors'][0] ?? 'Unknown error')];
}

/** HEAD request following redirects; 0 means no response at all. */
function http_status(string $host): int {
    $ch = curl_init("https://{$host}");
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

function open_db_if_present(): ?PDO {
    if (!file_exists(DB_PATH)) {
        return null;
    }
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function record_upgrade(?PDO $pdo, string $user, string $vhost, string $version): void {
    if ($pdo === null) {
        return;
    }
    try {
        $pdo->prepare('UPDATE whm_vhost_php_versions SET php_version = :v WHERE vhost = :vh AND whm_source = :s')
            ->execute([':v' => $version, ':vh' => $vhost, ':s' => WHM_SOURCE_LABEL]);
        $pdo->prepare('UPDATE whm_accounts SET php_version = :v WHERE username = :u AND domain = :vh AND whm_source = :s')
            ->execute([':v' => $version, ':u' => $user, ':vh' => $vhost, ':s' => WHM_SOURCE_LABEL]);
    } catch (Throwable $e) {
        fwrite(STDERR, "  (could not update local DB: {$e->getMessage()})\n");
    }
}

function run(array $args): int {
    $apply       = isset($args['apply']);
    $primaryOnly = isset($args['primary-only']);
    $onlyUser    = $args['user'] ?? null;
    $target      = $args['target'] ?? env('PHP_TARGET_VERSION', 'ea-php83');

    if ($onlyUser === true) {
        fwrite(STDERR, "--user needs a value, e.g. --user=cpuser\n");
        return 1;
    }
    $targetNum = is_string($target) ? php_version_number($target) : null;
    if ($targetNum === null) {
        fwrite(STDERR, "Target must look like ea-php83, got: " . var_export($target, true) . "\n");
        return 1;
    }

    $accounts = whm_list_accounts();
    if ($onlyUser !== null) {
        $accounts = array_values(array_filter($accounts, fn ($a) => ($a['user'] ?? '') === $onlyUser));
    }
    if (!$accounts) {
        fwrite(STDERR, $onlyUser !== null
            ? "No account named {$onlyUser} on " . WHM_SOURCE_LABEL . ".\n"
            : "No accounts returned - check your credentials/permissions.\n");
        return 1;
    }

    $pdo = $apply ? open_db_if_present() : null;

    echo ($apply ? 'APPLYING' : 'DRY RUN') . " on " . WHM_SOURCE_LABEL . ": "
        . count($accounts) . " account(s), target {$target}"
        . ($primaryOnly ? ', primary domains only' : '') . "\n\n";
    printf("%-15s | %-38s | %-10s | %s\n", 'USER', 'VHOST', 'CURRENT', 'RESULT');
    echo str_repeat('-', 96) . "\n";

    $stats = ['outdated' => 0, 'upgraded' => 0, 'failed' => 0, 'suspended' => 0, 'lookup_errors' => 0];
    $flagged = [];
    $consecutiveLookupErrors = 0;

    foreach ($accounts as $acct) {
        $user    = $acct['user'] ?? '';
        $primary = $acct['domain'] ?? '';

        if (!empty($acct['suspended'])) {
            $stats['suspended']++;
            continue;
        }

        try {
            $vhosts = get_vhost_versions($user);
            $consecutiveLookupErrors = 0;
        } catch (Throwable $e) {
            printf("%-15s | %-38s | %-10s | LOOKUP ERROR: %s\n", $user, $primary, '-', $e->getMessage());
            $stats['lookup_errors']++;
            // Same circuit-breaker as the other fleet scripts: if it fails
            // identically for every account, stop instead of hammering WHM.
            if (++$consecutiveLookupErrors >= 3) {
                fwrite(STDERR, "\n3 consecutive lookup failures - stopping early. Check WHM credentials / reseller ACLs.\n");
                break;
            }
            continue;
        }

        foreach ($vhosts as $v) {
            $vhost   = (string) ($v['vhost'] ?? '');
            $current = (string) ($v['version'] ?? '');
            if ($vhost === '' || ($primaryOnly && $vhost !== $primary)) {
                continue;
            }

            $n = php_version_number($current);
            if ($n === null || $n >= $targetNum) {
                continue; // already current, or not an ea-php version we manage
            }
            $stats['outdated']++;

            if (!$apply) {
                printf("%-15s | %-38s | %-10s | would upgrade -> %s\n", $user, $vhost, $current, $target);
                continue;
            }

            [$ok, $error] = set_vhost_version($user, $vhost, $target);
            if (!$ok) {
                printf("%-15s | %-38s | %-10s | FAILED: %s\n", $user, $vhost, $current, $error);
                $stats['failed']++;
                continue;
            }

            $stats['upgraded']++;
            record_upgrade($pdo, $user, $vhost, $target);

            $status = http_status($vhost);
            printf("%-15s | %-38s | %-10s | upgraded -> %s | HTTP %s\n",
                $user, $vhost, $current, $target, $status === 0 ? 'no response' : $status);
            if ($status === 0 || $status >= 500) {
                $flagged[] = "{$vhost} ({$user}) was {$current}, HTTP " . ($status ?: 'no response');
            }
        }

        usleep(300000); // be gentle on WHM between accounts
    }

    echo "\nSummary: {$stats['outdated']} outdated vhost(s)";
    if ($apply) {
        echo ", {$stats['upgraded']} upgraded, {$stats['failed']} failed";
    }
    echo ", {$stats['suspended']} suspended account(s) skipped, {$stats['lookup_errors']} lookup error(s).\n";

    if ($flagged) {
        echo "\nCheck these sites - they upgraded but did not answer cleanly (roll back in WHM -> MultiPHP Manager if needed):\n";
        foreach ($flagged as $line) {
            echo "  - {$line}\n";
        }
    }
    if (!$apply && $stats['outdated'] > 0) {
        echo "\nDry run - nothing was changed. Re-run with --apply to upgrade.\n";
    }

    return $stats['failed'] > 0 || $stats['lookup_errors'] > 0 ? 2 : 0;
}

try {
    exit(run(cli_args($argv)));
} catch (Throwable $e) {
    fwrite(STDERR, 'Fatal: ' . $e->getMessage() . "\n");
    exit(1);
}
