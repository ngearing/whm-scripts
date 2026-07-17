<?php

/**
 * check_wordpress.php
 *
 * "Check cPanels for WordPress installed" - the third item on your original
 * list. For every account belonging to one WHM source, this:
 *
 *   1. Calls DomainInfo::domains_data (format=hash) to get the REAL
 *      document root for the main domain and every addon domain - this is
 *      more reliable than guessing paths from domain names, since addon
 *      domain docroots can be customized at creation time.
 *   2. Recursively scans each docroot (up to MAX_SCAN_DEPTH levels deep,
 *      default 2) via Fileman::list_files, checking every folder visited
 *      for wp-config.php. This catches WordPress installed in subfolders
 *      (e.g. domain.com/blog, domain.com/site1) alongside a static page
 *      at the root - a single wp-config.php check at the docroot alone
 *      misses these, which is exactly what subfolder installs looked like
 *      in testing.
 *   3. For every install found, makes one more call to read
 *      wp-includes/version.php and extract the $wp_version string, so you
 *      get the version too, not just a yes/no.
 *
 * A single domain can end up with multiple rows in the results if it has
 * more than one WordPress install in different subfolders - that's
 * expected, not a bug.
 *
 * Like sync_whm_accounts.php, this is scoped to one WHM_SOURCE_LABEL per
 * run - run it once per reseller account. It reads the account list from
 * your already-synced whm_accounts table rather than re-calling listaccts,
 * so make sure sync_whm_accounts.php has been run for this source first.
 *
 * Usage:
 *   WHM_SOURCE_LABEL=shock-1 WHM_HOST=... WHM_USERNAME=... WHM_API_TOKEN=... php check_wordpress.php
 *   WHM_SOURCE_LABEL=shock-2 WHM_HOST=... WHM_USERNAME=... WHM_API_TOKEN=... php check_wordpress.php
 */

define('WHM_HOST', getenv('WHM_HOST') ?: 'YOUR_SERVER_HOSTNAME');
define('WHM_PORT', getenv('WHM_PORT') ?: '2087');
define('WHM_USERNAME', getenv('WHM_USERNAME') ?: 'YOUR_WHM_USERNAME');
define('WHM_API_TOKEN', getenv('WHM_API_TOKEN') ?: 'YOUR_API_TOKEN');
define('WHM_SOURCE_LABEL', getenv('WHM_SOURCE_LABEL') ?: (WHM_HOST . ':' . WHM_USERNAME));
define('DB_PATH', __DIR__ . '/websites.sqlite');
define('CSV_PATH', __DIR__ . '/wordpress_report_' . WHM_SOURCE_LABEL . '.csv');

// ---------------------------------------------------------------------
// DB
// ---------------------------------------------------------------------
function migrate_legacy_schema(PDO $pdo): void {
    $exists = $pdo->query("
        SELECT name FROM sqlite_master WHERE type='table' AND name='wp_installs'
    ")->fetchColumn();

    if (!$exists) {
        return;
    }

    $columns = $pdo->query("PRAGMA table_info(wp_installs)")->fetchAll(PDO::FETCH_ASSOC);
    $pkColumnCount = 0;
    foreach ($columns as $col) {
        if ((int) $col['pk'] > 0) {
            $pkColumnCount++;
        }
    }

    // Old schema had a 2-column primary key (domain, whm_source), which
    // can't support multiple installs per domain (subfolder installs).
    // New schema uses a 3-column key including docroot. Just rebuild -
    // this table is a fully reproducible cache, nothing lost by re-running.
    if ($pkColumnCount < 3) {
        echo "Migrating wp_installs to multi-install-per-domain schema (old data will be re-checked fresh)...\n";
        $pdo->exec("DROP TABLE wp_installs");
    }
}

function get_db(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    migrate_legacy_schema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wp_installs (
            domain           TEXT,
            whm_source       TEXT,
            account          TEXT,
            domain_type      TEXT,
            docroot          TEXT,
            has_wordpress    INTEGER,
            wp_version       TEXT,
            checked_at       TEXT,
            PRIMARY KEY (domain, docroot, whm_source)
        )
    ");

    return $pdo;
}

function clear_existing_source_data(PDO $pdo, string $source): void {
    $pdo->prepare("DELETE FROM wp_installs WHERE whm_source = :source")
        ->execute([':source' => $source]);
}

// ---------------------------------------------------------------------
// WHM API HELPER (same pattern as sync_whm_accounts.php)
// ---------------------------------------------------------------------
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
        CURLOPT_HTTPHEADER     => ['Authorization: whm ' . WHM_USERNAME . ':' . WHM_API_TOKEN],
        CURLOPT_TIMEOUT        => 60,
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

function uapi_call(string $username, string $module, string $function, array $extraParams = []): array {
    $params = array_merge([
        'cpanel.user'     => $username,
        'cpanel.module'   => $module,
        'cpanel.function' => $function,
    ], $extraParams);

    $response = whm_api_call('uapi_cpanel', $params);
    return $response['data']['uapi'] ?? [];
}

// ---------------------------------------------------------------------
// DOCROOT DISCOVERY
// ---------------------------------------------------------------------
/**
 * Returns a list of ['domain' => ..., 'domain_type' => ..., 'docroot' => ...
 * (absolute), 'docroot_relative' => ... (relative to homedir, for Fileman
 * calls), 'homedir' => ...] for every domain on this account that has a
 * document root (main + addon; parked domains alias the main domain's
 * docroot and sub_domains are typically not separate "sites" in the sense
 * we care about here, so both are skipped).
 */
function get_docroots_for_account(string $username): array {
    $result = uapi_call($username, 'DomainInfo', 'domains_data', ['format' => 'hash']);
    $data = $result['data'] ?? [];

    $entries = [];

    $mainDomain = $data['main_domain'] ?? null;
    if ($mainDomain && !empty($mainDomain['documentroot'])) {
        $entries[] = build_docroot_entry($mainDomain, 'main');
    }

    foreach ($data['addon_domains'] ?? [] as $addon) {
        if (!empty($addon['documentroot'])) {
            $entries[] = build_docroot_entry($addon, 'addon');
        }
    }

    return $entries;
}

function build_docroot_entry(array $domainData, string $type): array {
    $homedir = $domainData['homedir'] ?? '';
    $docroot = $domainData['documentroot'] ?? '';
    $relative = $docroot;
    if ($homedir !== '' && str_starts_with($docroot, $homedir)) {
        $relative = ltrim(substr($docroot, strlen($homedir)), '/');
    }

    return [
        'domain'           => $domainData['domain'] ?? null,
        'domain_type'      => $type,
        'docroot'          => $docroot,
        'docroot_relative' => $relative,
        'homedir'          => $homedir,
    ];
}

// ---------------------------------------------------------------------
// WORDPRESS DETECTION (recursive, bounded depth)
// ---------------------------------------------------------------------

// How many levels deep to search below each docroot. 0 = docroot only,
// 1 = docroot + one level of subfolders, etc. Override with env var if
// your sites tend to nest installs deeper (e.g. /clients/acme/blog).
define('MAX_SCAN_DEPTH', (int) (getenv('WP_SCAN_MAX_DEPTH') ?: 2));

// Hard cap on directories visited per docroot tree, as a safety valve
// against sites with enormous folder counts (huge media libraries, etc.)
// blowing out the runtime. Override with env var if needed.
define('MAX_DIRS_PER_SITE', (int) (getenv('WP_SCAN_MAX_DIRS') ?: 150));

// Directories that are never worth descending into when hunting for
// separate WordPress installs - either internal to an already-found
// install, or generic non-site folders common across hosting accounts.
const EXCLUDED_DIR_NAMES = [
    'wp-admin',
    'wp-includes',
    'wp-content',
    'cgi-bin',
    'cache',
    'tmp',
    'temp',
    'logs',
    'log',
    'node_modules',
    'vendor',
    '.git',
    '.svn',
    '.well-known',
    '.cpanel',
    'backup',
    'backups',
    'stats',
    'webalizer',
    'phpmyadmin',
];

/**
 * Breadth-first search under $rootPath, up to MAX_SCAN_DEPTH levels deep,
 * looking for wp-config.php in each directory visited. Once a directory is
 * confirmed as a WordPress install, its own subfolders are not explored
 * further (they're WP internals, not separate sites) - but sibling
 * directories at the same level still are, so multiple independent
 * installs under one docroot (e.g. /blog and /shop) are both found.
 *
 * Returns an array of ['docroot' => absolute path, 'wp_version' => string|null]
 * - one entry per install found. Empty array if none found anywhere in
 * the scanned tree.
 */
function scan_for_wordpress_installs(string $username, string $rootPath): array {
    $found = [];
    $queue = [[$rootPath, 0]];
    $visited = 0;

    while (!empty($queue)) {
        [$path, $depth] = array_shift($queue);
        $visited++;

        if ($visited > MAX_DIRS_PER_SITE) {
            fwrite(STDERR, "    [scan limit] hit " . MAX_DIRS_PER_SITE . " directories under {$rootPath} - stopping early, results may be incomplete\n");
            break;
        }

        try {
            $result = uapi_call($username, 'Fileman', 'list_files', [
                'dir'   => $path,
                'types' => 'file|dir',
            ]);
        } catch (Throwable $e) {
            // Unreadable directory (permissions, symlink weirdness, etc.)
            // - skip it rather than aborting the whole scan.
            continue;
        }

        $entries = $result['data'] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }

        $hasWpConfig = false;
        $subdirs = [];

        foreach ($entries as $e) {
            $name = $e['file'] ?? '';
            $type = $e['type'] ?? '';

            if ($type === 'file' && $name === 'wp-config.php') {
                $hasWpConfig = true;
            } elseif ($type === 'dir') {
                if ($name === '' || str_starts_with($name, '.')) {
                    continue;
                }
                if (in_array(strtolower($name), EXCLUDED_DIR_NAMES, true)) {
                    continue;
                }
                $subdirs[] = $name;
            }
        }

        if ($hasWpConfig) {
            $found[] = [
                'docroot'    => $path,
                'wp_version' => fetch_wp_version($username, $path),
            ];
            // Don't descend into a confirmed install's own subfolders -
            // they're WP internals, not separate sites.
            continue;
        }

        if ($depth < MAX_SCAN_DEPTH) {
            foreach ($subdirs as $sub) {
                $queue[] = [rtrim($path, '/') . '/' . $sub, $depth + 1];
            }
        }

        usleep(80000); // 0.08s between directory listing calls
    }

    return $found;
}

function fetch_wp_version(string $username, string $installPath): ?string {
    try {
        $versionFileDir = rtrim($installPath, '/') . '/wp-includes';
        $content = uapi_call($username, 'Fileman', 'get_file_content', [
            'dir'  => $versionFileDir,
            'file' => 'version.php',
        ]);
        $fileContent = $content['data']['content'] ?? '';
        if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $fileContent, $m)) {
            return $m[1];
        }
    } catch (Throwable $e) {
        // Non-fatal - we still know WP is installed even without a version.
    }
    return null;
}

// ---------------------------------------------------------------------
// RUN
// ---------------------------------------------------------------------
function run(): void {
    $pdo = get_db();

    $accounts = $pdo->prepare("SELECT username, domain FROM whm_accounts WHERE whm_source = :source");
    $accounts->execute([':source' => WHM_SOURCE_LABEL]);
    $accounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

    if (count($accounts) === 0) {
        fwrite(STDERR, "No accounts found for source '" . WHM_SOURCE_LABEL . "' in whm_accounts - run sync_whm_accounts.php for this source first.\n");
        exit(1);
    }

    echo "Checking " . count($accounts) . " accounts for WordPress installs (source: " . WHM_SOURCE_LABEL . ")...\n";
    echo "Scanning up to " . MAX_SCAN_DEPTH . " folder levels deep per docroot (set WP_SCAN_MAX_DEPTH to change), so subfolder installs like /blog get caught too.\n";
    echo "This is the slowest script in the pipeline - budget real time for a full run.\n";

    clear_existing_source_data($pdo, WHM_SOURCE_LABEL);

    $insert = $pdo->prepare("
        INSERT INTO wp_installs (domain, whm_source, account, domain_type, docroot, has_wordpress, wp_version, checked_at)
        VALUES (:domain, :whm_source, :account, :domain_type, :docroot, :has_wordpress, :wp_version, :checked_at)
    ");

    $totalSites = 0;
    $wpFound = 0;
    $errorCount = 0;
    $i = 0;
    $syncedAt = date('c');

    foreach ($accounts as $acct) {
        $i++;
        $username = $acct['username'];

        try {
            $docroots = get_docroots_for_account($username);
        } catch (Throwable $e) {
            fwrite(STDERR, "  [{$username}] Failed to get docroots: " . $e->getMessage() . "\n");
            $errorCount++;
            continue;
        }

        // Dedupe by absolute docroot - some domains legitimately share one
        // (e.g. a parked-style setup where an "addon" was pointed at the
        // main docroot), and there's no point checking the same folder
        // for wp-config.php twice.
        $seenDocroots = [];

        foreach ($docroots as $entry) {
            if (in_array($entry['docroot'], $seenDocroots, true)) {
                continue;
            }
            $seenDocroots[] = $entry['docroot'];

            try {
                $installs = scan_for_wordpress_installs($username, $entry['docroot']);
            } catch (Throwable $e) {
                fwrite(STDERR, "  [{$username}] WordPress scan failed for {$entry['domain']}: " . $e->getMessage() . "\n");
                $errorCount++;
                continue;
            }

            if (count($installs) === 0) {
                // Nothing found anywhere in the scanned tree - record a
                // single "checked, nothing found" row so this site shows
                // up as covered rather than silently missing from results.
                try {
                    $insert->execute([
                        ':domain'        => $entry['domain'],
                        ':whm_source'    => WHM_SOURCE_LABEL,
                        ':account'       => $username,
                        ':domain_type'   => $entry['domain_type'],
                        ':docroot'       => $entry['docroot'],
                        ':has_wordpress' => 0,
                        ':wp_version'    => null,
                        ':checked_at'    => $syncedAt,
                    ]);
                } catch (PDOException $e) {
                    // Ignore rare duplicate-key races within one run.
                }
                $totalSites++;
            } else {
                foreach ($installs as $install) {
                    try {
                        $insert->execute([
                            ':domain'        => $entry['domain'],
                            ':whm_source'    => WHM_SOURCE_LABEL,
                            ':account'       => $username,
                            ':domain_type'   => $entry['domain_type'],
                            ':docroot'       => $install['docroot'],
                            ':has_wordpress' => 1,
                            ':wp_version'    => $install['wp_version'],
                            ':checked_at'    => $syncedAt,
                        ]);
                        $wpFound++;
                    } catch (PDOException $e) {
                        // Same domain+docroot already recorded this run -
                        // shouldn't normally happen, safe to skip.
                    }
                    $totalSites++;
                }
            }

            usleep(80000);
        }

        if ($i % 25 === 0) {
            echo "  ...processed {$i}/" . count($accounts) . " accounts\n";
        }

        usleep(100000);
    }

    // CSV export for this source
    $fh = fopen(CSV_PATH, 'w');
    fputcsv($fh, ['domain', 'account', 'domain_type', 'docroot', 'has_wordpress', 'wp_version'], ',', '"', '\\');
    $rows = $pdo->prepare("SELECT * FROM wp_installs WHERE whm_source = :source ORDER BY has_wordpress DESC, domain");
    $rows->execute([':source' => WHM_SOURCE_LABEL]);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($fh, [
            $r['domain'],
            $r['account'],
            $r['domain_type'],
            $r['docroot'],
            $r['has_wordpress'] ? 'yes' : 'no',
            $r['wp_version'],
        ], ',', '"', '\\');
    }
    fclose($fh);

    echo "\n=== WordPress Detection Summary (source: " . WHM_SOURCE_LABEL . ") ===\n";
    echo "WordPress installs found: {$wpFound}\n";
    echo "Docroot trees checked with nothing found: " . ($totalSites - $wpFound) . "\n";
    echo "Errors during check: {$errorCount}\n";
    echo "\nFull results written to " . CSV_PATH . "\n";
    echo "Also queryable in websites.sqlite -> wp_installs table\n";
}

try {
    run();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
