<?php

/**
 * check_autossl_exclusions.php
 *
 * Audits which domains are excluded from AutoSSL on every cPanel account of
 * a WHM reseller (SSL::get_autossl_excluded_domains, run as each user through
 * the uapi_cpanel proxy). Excluded domains never get a free certificate
 * issued or renewed, so they are the first place to look when a site's SSL
 * expires. Replaces the old Node ssl.js.
 *
 * Read-only: nothing on the server is changed.
 *
 * Usage:
 *   php check_autossl_exclusions.php
 *   WHM_SOURCE_LABEL=shock-2 php check_autossl_exclusions.php
 *
 * Output:
 *   output/autossl_exclusions_<source>.csv
 *     one row per excluded domain (account, primary_domain, excluded_domain),
 *     plus a row with the error text for any account that could not be checked.
 *     Accounts with no exclusions are not listed (the count is in the summary).
 *
 * Credentials come from .env / the environment (see bootstrap.php).
 */

require __DIR__ . '/bootstrap.php';
define_whm_config();
require __DIR__ . '/lib/whm.php';

/**
 * @return string[] excluded domains for one account
 * @throws RuntimeException when WHM/cPanel reports an error
 */
function get_autossl_exclusions(string $user): array {
    $res = with_retries(fn () => uapi_call($user, 'SSL', 'get_autossl_excluded_domains'));

    if (isset($res['status']) && (int) $res['status'] === 0) {
        throw new RuntimeException($res['errors'][0] ?? 'get_autossl_excluded_domains failed');
    }

    $domains = [];
    foreach (($res['data'] ?? []) as $entry) {
        // cPanel documents entries as {"excluded_domain": "..."}; also accept
        // {"domain": "..."} or a bare string in case a version differs.
        $domains[] = is_array($entry)
            ? (string) ($entry['excluded_domain'] ?? $entry['domain'] ?? json_encode($entry))
            : (string) $entry;
    }
    return $domains;
}

function run(): int {
    $accounts = whm_list_accounts();
    if (!$accounts) {
        fwrite(STDERR, "No accounts returned - check your credentials/permissions.\n");
        return 1;
    }

    $total = count($accounts);
    echo "Checking AutoSSL exclusions for {$total} account(s) on " . WHM_SOURCE_LABEL . "...\n\n";
    printf("%-15s | %-40s | %s\n", 'USER', 'DOMAIN', 'EXCLUDED DOMAINS');
    echo str_repeat('-', 90) . "\n";

    $rows = [];
    $withExclusions = 0;
    $errors = 0;
    $consecutiveErrors = 0;

    foreach ($accounts as $i => $acct) {
        $user   = $acct['user'] ?? '';
        $domain = $acct['domain'] ?? '';

        try {
            $excluded = get_autossl_exclusions($user);
            $consecutiveErrors = 0;

            if ($excluded) {
                $withExclusions++;
                foreach ($excluded as $d) {
                    $rows[] = [$user, $domain, $d, ''];
                }
            }
            printf("%-15s | %-40s | %s\n", $user, $domain, $excluded ? implode(', ', $excluded) : 'None');
        } catch (Throwable $e) {
            $errors++;
            $rows[] = [$user, $domain, '', $e->getMessage()];
            printf("%-15s | %-40s | ERROR: %s\n", $user, $domain, $e->getMessage());

            if (++$consecutiveErrors >= 3) {
                fwrite(STDERR, "\n3 consecutive failures - stopping early. Check WHM credentials / reseller ACLs.\n");
                break;
            }
        }

        usleep(500000); // delay between accounts regardless of outcome
    }

    $csvPath = output_path('autossl_exclusions_' . safe_filename_part(WHM_SOURCE_LABEL) . '.csv');
    $fh = fopen($csvPath, 'w');
    fputcsv($fh, ['account', 'primary_domain', 'excluded_domain', 'error'], ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ',', '"', '\\');
    }
    fclose($fh);

    echo "\nSummary: {$withExclusions} of {$total} account(s) have AutoSSL exclusions, {$errors} could not be checked.\n";
    echo "Full results written to {$csvPath}\n";

    return $errors > 0 ? 2 : 0;
}

try {
    exit(run());
} catch (Throwable $e) {
    fwrite(STDERR, 'Fatal: ' . $e->getMessage() . "\n");
    exit(1);
}
