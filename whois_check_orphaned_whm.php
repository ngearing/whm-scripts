<?php

/**
 * whois_check_orphaned_whm.php
 *
 * Step 4 (optional follow-up). Many "ORPHANED_WHM" results from
 * reconcile_domains.php aren't actually a problem - they're just domains
 * the client registers and controls themselves elsewhere, while you host
 * the site. This script runs a WHOIS lookup on every ORPHANED_WHM domain
 * and classifies each one:
 *
 *   REGISTERED_ELSEWHERE   - has an active registrar that isn't Synergy.
 *                            This is the "client owns their own domain"
 *                            case you flagged - no action needed.
 *   REGISTERED_WITH_SYNERGY - WHOIS shows Synergy as registrar, but it
 *                            didn't match in the sync. Usually a data
 *                            issue (naming mismatch, new domain added
 *                            since last sync) worth a second look.
 *   NOT_REGISTERED         - WHOIS shows no active registration at all.
 *                            HIGH PRIORITY: a site is live on Shock but
 *                            the domain isn't registered to anyone -
 *                            it could be grabbed by someone else at any
 *                            time, taking the site down with it.
 *   LOOKUP_UNCLEAR         - WHOIS responded but couldn't be confidently
 *                            parsed (varies a lot by TLD/registry).
 *                            Needs a manual look.
 *   LOOKUP_FAILED          - the WHOIS query itself failed (timeout,
 *                            network issue, no whois server for TLD).
 *
 * Requires the system `whois` command to be installed (ships by default
 * on macOS and most Linux distros - check with `which whois`).
 *
 * Usage:
 *   php whois_check_orphaned_whm.php
 *
 * Run this after reconcile_domains.php.
 */

define('DB_PATH', __DIR__ . '/websites.sqlite');
define('CSV_PATH', __DIR__ . '/whois_orphaned_whm_report.csv');

// Seconds to wait between lookups. .au domains in particular (auDA's WHOIS
// registry) rate-limit aggressively and will start returning empty/blocked
// responses if queried too fast - 2s is a reasonably safe default for a
// batch of ~100-150 domains. Bump this up if you see a run of
// LOOKUP_UNCLEAR results in a row, which is the usual symptom of rate
// limiting kicking in partway through.
define('DELAY_SECONDS', 2);

function get_db(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function check_whois_available(): void {
    $path = trim((string) shell_exec('which whois 2>/dev/null'));
    if ($path === '') {
        fwrite(STDERR, "The 'whois' command isn't available on this system. On macOS it ships by default; on Linux install it with your package manager (e.g. apt install whois).\n");
        exit(1);
    }
}

/**
 * Runs `whois <domain>` and returns the raw text output, or null on
 * failure/timeout.
 */
function run_whois(string $domain): ?string {
    $safeDomain = escapeshellarg($domain);

    $descriptorSpec = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $process = proc_open("whois {$safeDomain}", $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return null;
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $start = time();
    $timeoutSeconds = 15;

    while (true) {
        $status = proc_get_status($process);
        $output .= stream_get_contents($pipes[1]);

        if (!$status['running']) {
            break;
        }
        if (time() - $start > $timeoutSeconds) {
            proc_terminate($process);
            break;
        }
        usleep(100000); // 0.1s poll interval
    }

    $output .= stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $output !== '' ? $output : null;
}

/**
 * Classifies raw WHOIS text into one of the statuses described in the
 * file header. WHOIS output format varies a lot by TLD/registry, so this
 * uses a handful of pattern attempts rather than one strict format.
 */
function classify_whois(string $domain, ?string $raw): array {
    if ($raw === null || trim($raw) === '') {
        return ['status' => 'LOOKUP_FAILED', 'registrar' => null, 'raw_snippet' => null];
    }

    // Common "not registered" phrases across different registries.
    $notFoundPatterns = [
        '/no match/i',
        '/not found/i',
        '/no data found/i',
        '/no entries found/i',
        '/status:\s*available/i',
        '/domain not found/i',
        '/no matching record/i',
    ];
    foreach ($notFoundPatterns as $pattern) {
        if (preg_match($pattern, $raw)) {
            return ['status' => 'NOT_REGISTERED', 'registrar' => null, 'raw_snippet' => extract_snippet($raw)];
        }
    }

    // Try a handful of registrar line formats seen across gTLD and
    // ccTLD (including auDA) WHOIS output.
    $registrar = null;
    $registrarPatterns = [
        '/^Registrar:\s*(.+)$/mi',
        '/^Registrar Name:\s*(.+)$/mi',
        '/^Sponsoring Registrar:\s*(.+)$/mi',
    ];
    foreach ($registrarPatterns as $pattern) {
        if (preg_match($pattern, $raw, $m)) {
            $candidate = trim($m[1]);
            // Skip lines that are actually "Registrar WHOIS Server:" etc.
            // matched loosely by the pattern above on some outputs.
            if ($candidate !== '' && stripos($candidate, 'whois.') !== 0) {
                $registrar = $candidate;
                break;
            }
        }
    }

    if ($registrar !== null) {
        if (stripos($registrar, 'synergy') !== false) {
            return ['status' => 'REGISTERED_WITH_SYNERGY', 'registrar' => $registrar, 'raw_snippet' => extract_snippet($raw)];
        }
        return ['status' => 'REGISTERED_ELSEWHERE', 'registrar' => $registrar, 'raw_snippet' => extract_snippet($raw)];
    }

    // Got a response, but couldn't confidently find a registrar or a
    // clear "not registered" signal - needs a human look. This is common
    // for .au domains under rate limiting, or thin WHOIS responses that
    // just point to a web-based lookup instead of returning full data.
    return ['status' => 'LOOKUP_UNCLEAR', 'registrar' => null, 'raw_snippet' => extract_snippet($raw)];
}

function extract_snippet(string $raw): string {
    // Keep a short snippet for manual review rather than storing the full
    // WHOIS blob for every domain.
    $lines = array_filter(array_map('trim', explode("\n", $raw)));
    $relevant = array_filter($lines, function ($line) {
        return preg_match('/registrar|status|expiry|expiration|updated/i', $line);
    });
    return implode(' | ', array_slice($relevant, 0, 4));
}

function run(): void {
    check_whois_available();

    $pdo = get_db();

    $domains = $pdo->query("
        SELECT domain, whm_account, whm_domain_type, whm_source
        FROM reconciliation_results
        WHERE status = 'ORPHANED_WHM'
        ORDER BY domain
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (count($domains) === 0) {
        echo "No ORPHANED_WHM domains found in reconciliation_results - run reconcile_domains.php first.\n";
        return;
    }

    echo "Running WHOIS lookups for " . count($domains) . " domains (roughly "
        . round(count($domains) * DELAY_SECONDS / 60, 1) . " minutes at "
        . DELAY_SECONDS . "s between lookups)...\n";

    $pdo->exec("DROP TABLE IF EXISTS whois_results");
    $pdo->exec("
        CREATE TABLE whois_results (
            domain          TEXT PRIMARY KEY,
            whois_status    TEXT,
            registrar       TEXT,
            raw_snippet     TEXT,
            whm_account     TEXT,
            whm_domain_type TEXT,
            whm_source      TEXT,
            checked_at      TEXT
        )
    ");
    $insert = $pdo->prepare("
        INSERT INTO whois_results
            (domain, whois_status, registrar, raw_snippet, whm_account, whm_domain_type, whm_source, checked_at)
        VALUES
            (:domain, :whois_status, :registrar, :raw_snippet, :whm_account, :whm_domain_type, :whm_source, :checked_at)
    ");

    $counts = [];
    $i = 0;

    foreach ($domains as $row) {
        $i++;
        $domain = $row['domain'];

        $raw = run_whois($domain);
        $classified = classify_whois($domain, $raw);

        $insert->execute([
            ':domain'          => $domain,
            ':whois_status'    => $classified['status'],
            ':registrar'       => $classified['registrar'],
            ':raw_snippet'     => $classified['raw_snippet'],
            ':whm_account'     => $row['whm_account'],
            ':whm_domain_type' => $row['whm_domain_type'],
            ':whm_source'      => $row['whm_source'],
            ':checked_at'      => date('c'),
        ]);

        $counts[$classified['status']] = ($counts[$classified['status']] ?? 0) + 1;

        echo "  [{$i}/" . count($domains) . "] {$domain} -> {$classified['status']}"
            . ($classified['registrar'] ? " ({$classified['registrar']})" : '') . "\n";

        if ($i < count($domains)) {
            sleep(DELAY_SECONDS);
        }
    }

    // Export CSV
    $fh = fopen(CSV_PATH, 'w');
    fputcsv($fh, ['domain', 'whois_status', 'registrar', 'whm_account', 'whm_domain_type', 'whm_source', 'raw_snippet'], ',', '"', '\\');
    $rows = $pdo->query("SELECT * FROM whois_results ORDER BY whois_status, domain")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        fputcsv($fh, [
            $r['domain'],
            $r['whois_status'],
            $r['registrar'],
            $r['whm_account'],
            $r['whm_domain_type'],
            $r['whm_source'],
            $r['raw_snippet'],
        ], ',', '"', '\\');
    }
    fclose($fh);

    echo "\n=== WHOIS Summary ===\n";
    foreach ($counts as $status => $count) {
        echo str_pad($status, 24) . ": {$count}\n";
    }

    if (!empty($counts['NOT_REGISTERED'])) {
        echo "\n*** {$counts['NOT_REGISTERED']} domain(s) are hosted but appear to have NO active registration. ***\n";
        echo "These are at risk of being registered by someone else while still live on your server:\n";
        foreach ($rows as $r) {
            if ($r['whois_status'] === 'NOT_REGISTERED') {
                echo "  {$r['domain']}  (account: {$r['whm_account']})\n";
            }
        }
    }

    echo "\nFull results written to " . CSV_PATH . "\n";
    echo "Also queryable in websites.sqlite -> whois_results table\n";
}

try {
    run();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
