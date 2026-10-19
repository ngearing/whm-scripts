<?php

/**
 * find_plugin_installs.php
 *
 * Finds every WordPress install (for one WHM source) that has a given plugin
 * on disk, and records the plugin version where it can be read.
 *
 * Why file-based rather than WP Toolkit?
 *   The WP Toolkit /v1/installations list response (what
 *   check_wordpress_wptoolkit.php stores) only carries plugin UPDATE COUNTS,
 *   not the plugin list. Getting the list from WP Toolkit would need a
 *   per-install call to an endpoint that hasn't been captured/confirmed, plus
 *   a cPanel session minted per account (needs the Create User Session ACL).
 *   A directory listing of wp-content/plugins needs only the UAPI proxy that
 *   check_wordpress.php already uses, and the install paths are already in
 *   websites.sqlite - so this makes ONE call per install (two with version).
 *
 * Install paths come from websites.sqlite (no re-discovery):
 *   - wp_installs_toolkit.path  (WP Toolkit + fallback scan results)
 *   - wp_installs.docroot where has_wordpress = 1  (check_wordpress.php)
 * The two are merged and de-duplicated per account + path.
 *
 * LIMITS (read before trusting a "not found"):
 *   - This detects INSTALLED, not ACTIVE. Active state lives in the DB
 *     (active_plugins option) - not visible from files.
 *   - Assumes the standard wp-content location. Installs that relocate
 *     wp-content via WP_CONTENT_DIR show up as 'no_plugins_dir'.
 *   - Slug = the plugin's folder name (e.g. "woocommerce"), not its display
 *     name. Single-file plugins (e.g. hello.php) are matched by filename too.
 *   - Also checks wp-content/mu-plugins.
 *
 * Usage:
 *   PLUGIN_SLUG=elementor-pro WHM_SOURCE_LABEL=shock-1 WHM_HOST=... \
 *     WHM_USERNAME=... WHM_API_TOKEN=... php find_plugin_installs.php
 *
 *   Optional env:
 *     PLUGIN_SLUG   plugin folder name (or pass as first CLI argument)
 *     PLUGIN_MATCH  'exact' (default) or 'contains' (e.g. "elementor" also
 *                   matches elementor-pro, elementor-extras)
 *     WITH_VERSION  1 (default) reads the plugin header for its version;
 *                   0 skips that call (about twice as fast)
 *     DB_PATH       override websites.sqlite location
 *
 * Output:
 *   - SQLite table plugin_installs (replaced per source + slug on each run)
 *   - output/plugin_report_<slug>_<source>.csv
 */

// ---------------------------------------------------------------------
// CONFIG - identical pattern to sync_whm_accounts.php
// ---------------------------------------------------------------------

require __DIR__ . '/bootstrap.php';
define_whm_config();

$slugArg = $argv[1] ?? (getenv('PLUGIN_SLUG') ?: '');
define('PLUGIN_SLUG', strtolower(trim($slugArg)));
define('PLUGIN_MATCH', getenv('PLUGIN_MATCH') ?: 'exact');
define('WITH_VERSION', (getenv('WITH_VERSION') ?: '1') !== '0');

if (PLUGIN_SLUG === '' || !preg_match('/^[a-z0-9._-]+$/', PLUGIN_SLUG)) {
    fwrite(STDERR, "Set PLUGIN_SLUG (or pass it as the first argument) to a plugin folder name, e.g. elementor-pro\n");
    exit(1);
}

// ---------------------------------------------------------------------
// DB
// ---------------------------------------------------------------------
function get_db(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS plugin_installs (
            plugin_slug     TEXT,
            whm_source      TEXT,
            account         TEXT,
            domain          TEXT,
            install_path    TEXT,
            found           INTEGER,
            location        TEXT,   -- 'plugins' | 'mu-plugins' | NULL
            matched_name    TEXT,   -- actual folder/file name matched
            plugin_version  TEXT,
            status          TEXT,   -- 'ok' | 'no_plugins_dir' | 'error'
            error_message   TEXT,
            checked_at      TEXT,
            PRIMARY KEY (plugin_slug, whm_source, account, install_path, matched_name)
        )
    ");

    return $pdo;
}

/**
 * Merges install paths from both scan sources into one de-duplicated list of
 * [account, domain, path].
 */
function load_installs(PDO $pdo, string $source): array {
    $installs = [];

    $stmt = $pdo->prepare("
        SELECT account, domain_name AS domain, path
        FROM wp_installs_toolkit
        WHERE whm_source = :s AND path IS NOT NULL AND path != ''
    ");
    $stmt->execute([':s' => $source]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $installs[$r['account'] . '|' . rtrim($r['path'], '/')] = $r;
    }

    $stmt = $pdo->prepare("
        SELECT account, domain, docroot AS path
        FROM wp_installs
        WHERE whm_source = :s AND has_wordpress = 1 AND docroot IS NOT NULL AND docroot != ''
    ");
    $stmt->execute([':s' => $source]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = $r['account'] . '|' . rtrim($r['path'], '/');
        if (!isset($installs[$key])) {
            $installs[$key] = $r;
        }
    }

    return array_values($installs);
}

// ---------------------------------------------------------------------
// WHM API HELPERS (same pattern as the other scripts)
// ---------------------------------------------------------------------
function whm_api_call(string $function, array $params = []): array {
    $params['api.version'] = 1;
    $url = sprintf('https://%s:%s/json-api/%s?%s', WHM_HOST, WHM_PORT, $function, http_build_query($params));

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
// PLUGIN CHECK
// ---------------------------------------------------------------------

/**
 * Lists one directory. Returns null if the directory couldn't be listed
 * (missing, or UAPI error), otherwise an array of ['name' => ..., 'type' => ...].
 */
function list_dir(string $username, string $dir): ?array {
    $result = uapi_call($username, 'Fileman', 'list_files', ['dir' => $dir, 'types' => 'file|dir']);

    if (isset($result['status']) && (int) $result['status'] !== 1) {
        return null;
    }

    $entries = $result['data'] ?? [];
    if (!is_array($entries)) {
        return [];
    }

    $out = [];
    foreach ($entries as $e) {
        $out[] = ['name' => $e['file'] ?? '', 'type' => $e['type'] ?? ''];
    }
    return $out;
}

function name_matches(string $name, string $slug): bool {
    $n = strtolower($name);
    // Single-file plugins: compare without the .php extension.
    if (str_ends_with($n, '.php')) {
        $n = substr($n, 0, -4);
    }
    return PLUGIN_MATCH === 'contains' ? str_contains($n, $slug) : $n === $slug;
}

/**
 * Reads the "Version:" line from the plugin's main file header.
 * Tries <folder>/<folder>.php first (the WordPress convention); returns null
 * if that file doesn't exist or has no header - non-fatal.
 */
function read_plugin_version(string $username, string $pluginsDir, string $folder): ?string {
    try {
        $dir = rtrim($pluginsDir, '/') . '/' . $folder;
        $res = uapi_call($username, 'Fileman', 'get_file_content', ['dir' => $dir, 'file' => $folder . '.php']);
        $content = $res['data']['content'] ?? '';
        // Plugin headers live in the first 8KB; don't regex a huge file.
        if (preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', substr($content, 0, 8192), $m)) {
            return trim($m[1]);
        }
    } catch (Throwable $e) {
        // Non-fatal.
    }
    return null;
}

/**
 * Checks one install. Returns a list of match rows (empty list = plugin not
 * found), plus a status describing whether the check itself worked.
 */
function check_install(string $username, string $installPath): array {
    $matches = [];
    $status = 'ok';
    $sawAnyDir = false;

    foreach (['plugins', 'mu-plugins'] as $loc) {
        $dir = rtrim($installPath, '/') . '/wp-content/' . $loc;
        $entries = list_dir($username, $dir);

        if ($entries === null) {
            continue; // mu-plugins commonly doesn't exist - only plugins matters for status
        }
        $sawAnyDir = true;

        foreach ($entries as $e) {
            if ($e['name'] === '' || !name_matches($e['name'], PLUGIN_SLUG)) {
                continue;
            }
            $version = null;
            if (WITH_VERSION && $e['type'] === 'dir') {
                $version = read_plugin_version($username, $dir, $e['name']);
            }
            $matches[] = ['location' => $loc, 'name' => $e['name'], 'version' => $version];
        }
    }

    if (!$sawAnyDir) {
        $status = 'no_plugins_dir';
    }

    return [$matches, $status];
}

// ---------------------------------------------------------------------
// RUN
// ---------------------------------------------------------------------
function run(): void {
    $pdo = get_db();
    $installs = load_installs($pdo, WHM_SOURCE_LABEL);

    if (count($installs) === 0) {
        fwrite(STDERR, "No installs found for source '" . WHM_SOURCE_LABEL . "' in wp_installs_toolkit / wp_installs - run check_wordpress.php or check_wordpress_wptoolkit.php for this source first.\n");
        exit(1);
    }

    echo "Checking " . count($installs) . " install(s) for plugin '" . PLUGIN_SLUG . "' (match: " . PLUGIN_MATCH
        . ", versions: " . (WITH_VERSION ? 'on' : 'off') . ", source: " . WHM_SOURCE_LABEL . ")...\n";

    // Replace previous results for this plugin + source only.
    $pdo->prepare("DELETE FROM plugin_installs WHERE plugin_slug = :p AND whm_source = :s")
        ->execute([':p' => PLUGIN_SLUG, ':s' => WHM_SOURCE_LABEL]);

    $insert = $pdo->prepare("
        INSERT OR REPLACE INTO plugin_installs
            (plugin_slug, whm_source, account, domain, install_path, found, location,
             matched_name, plugin_version, status, error_message, checked_at)
        VALUES
            (:slug, :source, :account, :domain, :path, :found, :location,
             :matched, :version, :status, :error, :checked_at)
    ");

    $checkedAt = date('c');
    $total = count($installs);
    $foundCount = 0;
    $errorCount = 0;
    $consecutiveFailures = 0;
    $i = 0;

    foreach ($installs as $inst) {
        $i++;
        $account = $inst['account'];
        $path = $inst['path'];

        try {
            [$matches, $status] = check_install($account, $path);
            $consecutiveFailures = 0;

            $base = [
                ':slug'       => PLUGIN_SLUG,
                ':source'     => WHM_SOURCE_LABEL,
                ':account'    => $account,
                ':domain'     => $inst['domain'],
                ':path'       => $path,
                ':checked_at' => $checkedAt,
                ':error'      => null,
            ];

            if (count($matches) > 0) {
                foreach ($matches as $m) {
                    $insert->execute($base + [
                        ':found'    => 1,
                        ':location' => $m['location'],
                        ':matched'  => $m['name'],
                        ':version'  => $m['version'],
                        ':status'   => 'ok',
                    ]);
                    $foundCount++;
                    echo "  [{$i}/{$total}] FOUND {$m['name']}" . ($m['version'] ? " v{$m['version']}" : '')
                        . " - " . ($inst['domain'] ?: $account) . " ({$path})\n";
                }
            } else {
                $insert->execute($base + [
                    ':found'    => 0,
                    ':location' => null,
                    ':matched'  => '',
                    ':version'  => null,
                    ':status'   => $status,
                ]);
                if ($status !== 'ok') {
                    echo "  [{$i}/{$total}] {$account}: {$status} ({$path})\n";
                }
            }
        } catch (Throwable $e) {
            $errorCount++;
            $consecutiveFailures++;
            $insert->execute([
                ':slug'       => PLUGIN_SLUG,
                ':source'     => WHM_SOURCE_LABEL,
                ':account'    => $account,
                ':domain'     => $inst['domain'],
                ':path'       => $path,
                ':found'      => 0,
                ':location'   => null,
                ':matched'    => '',
                ':version'    => null,
                ':status'     => 'error',
                ':error'      => $e->getMessage(),
                ':checked_at' => $checkedAt,
            ]);
            fwrite(STDERR, "  [{$i}/{$total}] {$account}: FAILED - " . $e->getMessage() . "\n");

            // Same fail-fast pattern as the other scripts: an auth/ACL problem
            // fails identically for every account.
            if ($consecutiveFailures >= 3) {
                fwrite(STDERR, "\n3 consecutive failures - stopping early. Check WHM credentials / reseller ACLs.\n");
                break;
            }
        }

        usleep(100000); // 0.1s between installs - gentle on WHM, ~2 min for 500 installs
    }

    // CSV of installs where the plugin was found (the useful output).
    $csvPath = output_path('plugin_report_' . PLUGIN_SLUG . '_' . safe_filename_part(WHM_SOURCE_LABEL) . '.csv');
    $fh = fopen($csvPath, 'w');
    fputcsv($fh, ['domain', 'account', 'plugin', 'version', 'location', 'install_path'], ',', '"', '\\');

    $rows = $pdo->prepare("
        SELECT * FROM plugin_installs
        WHERE plugin_slug = :p AND whm_source = :s AND found = 1
        ORDER BY domain, install_path
    ");
    $rows->execute([':p' => PLUGIN_SLUG, ':s' => WHM_SOURCE_LABEL]);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($fh, [$r['domain'], $r['account'], $r['matched_name'], $r['plugin_version'], $r['location'], $r['install_path']], ',', '"', '\\');
    }
    fclose($fh);

    $noDir = (int) $pdo->query("SELECT COUNT(*) FROM plugin_installs WHERE plugin_slug = " . $pdo->quote(PLUGIN_SLUG)
        . " AND whm_source = " . $pdo->quote(WHM_SOURCE_LABEL) . " AND status = 'no_plugins_dir'")->fetchColumn();

    echo "\nDone. {$total} install(s) checked, {$foundCount} match(es) for '" . PLUGIN_SLUG . "'.\n";
    if ($noDir > 0) {
        echo "{$noDir} install(s) had no readable wp-content/plugins (stale path or relocated wp-content) - status 'no_plugins_dir' in the table.\n";
    }
    if ($errorCount > 0) {
        echo "{$errorCount} install(s) errored - see status = 'error' in plugin_installs.\n";
    }
    echo "CSV: {$csvPath}\n";
    echo "Also queryable in websites.sqlite -> plugin_installs table\n";
}

run();
