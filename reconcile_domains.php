<?php

/**
 * reconcile_domains.php
 *
 * Step 3 of the pipeline. Diffs the domain list pulled from Synergy
 * Wholesale (synergy_domains) against the full domain inventory pulled
 * from Shock/WHM (whm_domains: main + addon + parked), and flags:
 *
 *   1. ORPHANED_SYNERGY  - registered in Synergy, no matching WHM domain
 *                          of any type. Either not hosted, hosted
 *                          elsewhere, or genuinely unused.
 *   2. ORPHANED_WHM      - hosted on Shock, no matching Synergy domain.
 *                          Either registered elsewhere, or a domain that
 *                          should have been cleaned up when a client left.
 *   3. MATCHED           - exists in both. Includes a DNS sanity check:
 *                          flags cases where Synergy's nameservers don't
 *                          look like they're pointed at Shock at all,
 *                          which usually means the domain is registered
 *                          with you but the site is live somewhere else.
 *
 *   4. DUPLICATE_WHM     - the same domain is hosted under BOTH Shock
 *                          reseller accounts. Not a Synergy-vs-WHM issue,
 *                          just flagged because it's worth knowing about
 *                          and cleaning up.
 *
 * Run this after sync_synergy_domains.php AND sync_whm_accounts.php have
 * been run for EVERY reseller account (each with its own WHM_SOURCE_LABEL -
 * see sync_whm_accounts.php). This script reads whatever is currently in
 * websites.sqlite, so it automatically covers all synced sources at once.
 *
 * Usage:
 *   php reconcile_domains.php
 *
 * Output:
 *   - Console summary
 *   - reconciliation_report.csv in the same directory
 *   - A `reconciliation_results` table in websites.sqlite, so this can be
 *     queried directly (e.g. from a future MainWP extension) instead of
 *     re-parsing the CSV
 */

define('DB_PATH', __DIR__ . '/websites.sqlite');
define('CSV_PATH', __DIR__ . '/reconciliation_report.csv');

// If your Shock nameservers differ from the defaults, update this list -
// it's used only for the informational DNS mismatch flag below, not for
// determining the core orphan/match status.
define('EXPECTED_NS_FRAGMENTS', ['greengraphics.com.au', 'cloudflare.com']);

function get_db(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * Normalizes a domain name for comparison: lowercase, strip a leading
 * "www.", strip any trailing dot. Synergy and WHM are generally consistent
 * about casing, but this avoids false-positive orphans from a stray case
 * or formatting difference between the two systems.
 */
function normalize_domain(?string $domain): ?string {
    if ($domain === null) {
        return null;
    }
    $d = strtolower(trim($domain));
    $d = rtrim($d, '.');
    if (str_starts_with($d, 'www.')) {
        $d = substr($d, 4);
    }
    return $d;
}

function reconcile(PDO $pdo): array {
    // --- Load Synergy domains ---
    $synergyRows = $pdo->query("
        SELECT domain_name, domain_status, domain_expiry, name_servers
        FROM synergy_domains
    ")->fetchAll(PDO::FETCH_ASSOC);

    $synergyByNorm = [];
    foreach ($synergyRows as $row) {
        $norm = normalize_domain($row['domain_name']);
        if ($norm !== null) {
            $synergyByNorm[$norm] = $row;
        }
    }

    // --- Load WHM domains (main + addon + parked, across BOTH reseller
    // accounts) ---
    $whmRows = $pdo->query("
        SELECT domain, account, domain_type, whm_source
        FROM whm_domains
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Group by normalized domain first, since with two reseller accounts
    // it's technically possible (and worth catching) for the same domain
    // to show up under both. Track all matches per domain rather than
    // just keeping the last one seen.
    $whmByNorm = [];
    foreach ($whmRows as $row) {
        $norm = normalize_domain($row['domain']);
        if ($norm !== null) {
            $whmByNorm[$norm][] = $row;
        }
    }

    $allDomains = array_unique(array_merge(array_keys($synergyByNorm), array_keys($whmByNorm)));
    sort($allDomains);

    $results = [];

    foreach ($allDomains as $domain) {
        $inSynergy = $synergyByNorm[$domain] ?? null;
        $whmMatches = $whmByNorm[$domain] ?? [];
        $inWhm = $whmMatches[0] ?? null; // primary match for display purposes

        // Flag the (hopefully rare) case where the same domain is hosted
        // under BOTH reseller accounts - a genuine account hygiene issue
        // worth fixing regardless of what Synergy says.
        $distinctSources = array_unique(array_column($whmMatches, 'whm_source'));
        if (count($distinctSources) > 1) {
            $accountList = implode(', ', array_map(
                fn($m) => "{$m['account']}@{$m['whm_source']} ({$m['domain_type']})",
                $whmMatches
            ));
            $results[] = [
                'domain'          => $domain,
                'status'          => 'DUPLICATE_WHM',
                'detail'          => "Domain is hosted under MORE THAN ONE reseller account: {$accountList}",
                'synergy_status'  => $inSynergy['domain_status'] ?? null,
                'synergy_expiry'  => $inSynergy['domain_expiry'] ?? null,
                'whm_account'     => $inWhm['account'] ?? null,
                'whm_domain_type' => $inWhm['domain_type'] ?? null,
                'whm_source'      => implode('+', $distinctSources),
            ];
            continue;
        }

        if ($inSynergy && !$inWhm) {
            // A blank/null expiry means Synergy is still listing this domain
            // in the account's history, but it's actually lapsed/expired and
            // no longer truly "yours" - not a real orphan requiring action,
            // just old data. Keep it in its own bucket so it doesn't pollute
            // the list of domains that genuinely need a decision made.
            $isLapsed = empty($inSynergy['domain_expiry']);

            $results[] = [
                'domain'        => $domain,
                'status'        => $isLapsed ? 'LAPSED_SYNERGY' : 'ORPHANED_SYNERGY',
                'detail'        => $isLapsed
                    ? 'Listed in Synergy account history but has no expiry date - already lapsed/expired, not currently registered'
                    : 'Registered in Synergy, no matching cPanel domain found on Shock',
                'synergy_status' => $inSynergy['domain_status'],
                'synergy_expiry' => $inSynergy['domain_expiry'],
                'whm_account'   => null,
                'whm_domain_type' => null,
                'whm_source'    => null,
            ];
            continue;
        }

        if ($inWhm && !$inSynergy) {
            $results[] = [
                'domain'        => $domain,
                'status'        => 'ORPHANED_WHM',
                'detail'        => "Hosted on Shock (account '{$inWhm['account']}' on '{$inWhm['whm_source']}', {$inWhm['domain_type']}), not found in Synergy domain list",
                'synergy_status' => null,
                'synergy_expiry' => null,
                'whm_account'   => $inWhm['account'],
                'whm_domain_type' => $inWhm['domain_type'],
                'whm_source'    => $inWhm['whm_source'],
            ];
            continue;
        }

        // Matched on both sides - check whether the nameservers actually
        // point at Shock, since a domain can be "matched" here but still
        // be live on a different host if DNS was never updated.
        $nsLooksLikeShock = false;
        $nameServers = [];
        if (!empty($inSynergy['name_servers'])) {
            $decoded = json_decode($inSynergy['name_servers'], true);
            if (is_array($decoded)) {
                $nameServers = $decoded;
                foreach ($nameServers as $ns) {
                    foreach (EXPECTED_NS_FRAGMENTS as $fragment) {
                        if (stripos((string) $ns, $fragment) !== false) {
                            $nsLooksLikeShock = true;
                            break 2;
                        }
                    }
                }
            }
        }

        $results[] = [
            'domain'          => $domain,
            'status'          => $nsLooksLikeShock ? 'MATCHED' : 'MATCHED_DNS_MISMATCH',
            'detail'          => $nsLooksLikeShock
                ? 'Registered in Synergy, hosted on Shock, nameservers point at Shock'
                : 'Registered in Synergy, hosted on Shock, but nameservers do NOT appear to point at Shock - verify this domain is actually live here (' . implode(', ', $nameServers) . ')',
            'synergy_status'  => $inSynergy['domain_status'],
            'synergy_expiry'  => $inSynergy['domain_expiry'],
            'whm_account'     => $inWhm['account'],
            'whm_domain_type' => $inWhm['domain_type'],
            'whm_source'      => $inWhm['whm_source'],
        ];
    }

    return $results;
}

function store_results(PDO $pdo, array $results, string $runAt): void {
    // This table is always fully rebuilt on each run, so drop-and-recreate
    // is simpler than migrating in place (and avoids needing separate
    // migration logic if the schema changes again later).
    $pdo->exec("DROP TABLE IF EXISTS reconciliation_results");
    $pdo->exec("
        CREATE TABLE reconciliation_results (
            domain            TEXT,
            status            TEXT,
            detail            TEXT,
            synergy_status    TEXT,
            synergy_expiry    TEXT,
            whm_account       TEXT,
            whm_domain_type   TEXT,
            whm_source        TEXT,
            run_at            TEXT
        )
    ");

    $insert = $pdo->prepare("
        INSERT INTO reconciliation_results
            (domain, status, detail, synergy_status, synergy_expiry, whm_account, whm_domain_type, whm_source, run_at)
        VALUES
            (:domain, :status, :detail, :synergy_status, :synergy_expiry, :whm_account, :whm_domain_type, :whm_source, :run_at)
    ");

    $pdo->beginTransaction();
    foreach ($results as $r) {
        $insert->execute([
            ':domain'          => $r['domain'],
            ':status'          => $r['status'],
            ':detail'          => $r['detail'],
            ':synergy_status'  => $r['synergy_status'],
            ':synergy_expiry'  => $r['synergy_expiry'],
            ':whm_account'     => $r['whm_account'],
            ':whm_domain_type' => $r['whm_domain_type'],
            ':whm_source'      => $r['whm_source'] ?? null,
            ':run_at'          => $runAt,
        ]);
    }
    $pdo->commit();
}

function write_csv(array $results): void {
    $fh = fopen(CSV_PATH, 'w');
    fputcsv($fh, ['domain', 'status', 'detail', 'synergy_status', 'synergy_expiry', 'whm_account', 'whm_domain_type', 'whm_source'], ',', '"', '\\');
    foreach ($results as $r) {
        fputcsv($fh, [
            $r['domain'],
            $r['status'],
            $r['detail'],
            $r['synergy_status'],
            $r['synergy_expiry'],
            $r['whm_account'],
            $r['whm_domain_type'],
            $r['whm_source'] ?? null,
        ], ',', '"', '\\');
    }
    fclose($fh);
}

function print_summary(array $results): void {
    $counts = [];
    foreach ($results as $r) {
        $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
    }

    echo "\n=== Reconciliation Summary ===\n";
    echo "Total domains examined: " . count($results) . "\n\n";
    foreach ($counts as $status => $count) {
        echo str_pad($status, 24) . ": {$count}\n";
    }

    if (!empty($counts['DUPLICATE_WHM'])) {
        echo "\n--- ACCOUNT HYGIENE: domains hosted under BOTH reseller accounts ---\n";
        $shown = 0;
        foreach ($results as $r) {
            if ($r['status'] === 'DUPLICATE_WHM' && $shown < 15) {
                echo "  {$r['domain']}  -  {$r['detail']}\n";
                $shown++;
            }
        }
        if ($counts['DUPLICATE_WHM'] > 15) {
            echo "  ... and " . ($counts['DUPLICATE_WHM'] - 15) . " more, see CSV for full list\n";
        }
    }

    echo "\n--- Orphaned in Synergy (currently registered, not found hosted on Shock) ---\n";
    $shown = 0;
    foreach ($results as $r) {
        if ($r['status'] === 'ORPHANED_SYNERGY' && $shown < 15) {
            echo "  {$r['domain']}  (expires {$r['synergy_expiry']})\n";
            $shown++;
        }
    }
    if (($counts['ORPHANED_SYNERGY'] ?? 0) > 15) {
        echo "  ... and " . ($counts['ORPHANED_SYNERGY'] - 15) . " more, see CSV for full list\n";
    }
    if (($counts['ORPHANED_SYNERGY'] ?? 0) === 0) {
        echo "  (none)\n";
    }

    if (!empty($counts['LAPSED_SYNERGY'])) {
        echo "\n--- Lapsed in Synergy (already expired, informational only - no action needed) ---\n";
        echo "  {$counts['LAPSED_SYNERGY']} domains with no expiry date on file - see CSV (status=LAPSED_SYNERGY) if you want the full list\n";
    }

    echo "\n--- Orphaned in WHM (hosted on Shock, not found in Synergy) ---\n";
    $shown = 0;
    foreach ($results as $r) {
        if ($r['status'] === 'ORPHANED_WHM' && $shown < 15) {
            echo "  {$r['domain']}  (account: {$r['whm_account']}, type: {$r['whm_domain_type']})\n";
            $shown++;
        }
    }
    if (($counts['ORPHANED_WHM'] ?? 0) > 15) {
        echo "  ... and " . ($counts['ORPHANED_WHM'] - 15) . " more, see CSV for full list\n";
    }

    if (!empty($counts['MATCHED_DNS_MISMATCH'])) {
        echo "\n--- DNS mismatch flags (matched, but nameservers don't look like Shock's) ---\n";
        $shown = 0;
        foreach ($results as $r) {
            if ($r['status'] === 'MATCHED_DNS_MISMATCH' && $shown < 15) {
                echo "  {$r['domain']}\n";
                $shown++;
            }
        }
        if ($counts['MATCHED_DNS_MISMATCH'] > 15) {
            echo "  ... and " . ($counts['MATCHED_DNS_MISMATCH'] - 15) . " more, see CSV for full list\n";
        }
    }

    echo "\nFull results written to " . CSV_PATH . "\n";
    echo "Also queryable in websites.sqlite -> reconciliation_results table\n";
}

// ---------------------------------------------------------------------
// RUN
// ---------------------------------------------------------------------
try {
    $pdo = get_db();

    // Sanity check the source tables actually have data before diffing -
    // an empty table usually means one of the two sync scripts hasn't been
    // run yet, and running the reconcile anyway would just report every
    // domain as orphaned on one side, which is misleading.
    $synergyCount = (int) $pdo->query("SELECT COUNT(*) FROM synergy_domains")->fetchColumn();
    $whmCount = (int) $pdo->query("SELECT COUNT(*) FROM whm_domains")->fetchColumn();

    if ($synergyCount === 0) {
        fwrite(STDERR, "synergy_domains table is empty - run sync_synergy_domains.php first.\n");
        exit(1);
    }
    if ($whmCount === 0) {
        fwrite(STDERR, "whm_domains table is empty - run sync_whm_accounts.php first.\n");
        exit(1);
    }

    echo "Reconciling {$synergyCount} Synergy domains against {$whmCount} WHM domain records...\n";

    $runAt = date('c');
    $results = reconcile($pdo);
    store_results($pdo, $results, $runAt);
    write_csv($results);
    print_summary($results);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
