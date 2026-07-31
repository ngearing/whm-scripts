<?php

/**
 * check_wordpress_wptoolkit.php
 *
 * Alternative to check_wordpress.php's recursive file scan - this uses WP
 * Toolkit's own internal API (the same one the cPanel "WordPress Toolkit"
 * page calls), which already knows every WordPress install per account
 * including subfolder installs, without us crawling the filesystem.
 *
 * WP Toolkit's API isn't part of standard UAPI/WHM API and authenticates
 * via a live cPanel session cookie, not the WHM API token. So this script:
 *
 *   1. Calls whmapi1 create_user_session (service=cpaneld) to mint a
 *      one-time login URL for the account - a documented, reseller-
 *      accessible WHM function (subject to your reseller ACLs).
 *   2. Fetches that login URL WITHOUT auto-following the redirect, pulls
 *      the cpsession cookie out of the Set-Cookie header by hand, then
 *      manually follows the redirect with that cookie attached to
 *      actually activate the session server-side. This mirrors a
 *      previously-confirmed-working Node.js implementation of the same
 *      handshake - a cookie-jar + auto-redirect approach was tried first
 *      and was less reliable for cPanel's specific login flow.
 *   3. Uses that cookie as an explicit Cookie header to call
 *      /3rdparty/wpt/index.php/v1/installations directly - the same
 *      endpoint the browser's WordPress Toolkit page calls, captured from
 *      your network tab. The cpsessXXXXXXXXXX path segment is parsed out
 *      of the login URL itself, the same way the Node.js version does.
 *
 * The response schema is now CONFIRMED from real captured output (not
 * guessed): each installation includes WP core version, PHP handler
 * version + EOL/unsupported flags, vulnerability status and risk score,
 * pending plugin/theme update counts, infection status, and SSL cert
 * info. All of that gets parsed into wp_installs_toolkit - genuinely
 * richer than what check_wordpress.php's file scan can determine on its
 * own, since none of that (vulnerabilities, EOL PHP, infections) is
 * visible just from checking for wp-config.php.
 *
 * Usage:
 *   WHM_SOURCE_LABEL=shock-1 WHM_HOST=... WHM_USERNAME=... WHM_API_TOKEN=... php check_wordpress_wptoolkit.php
 *
 * If create_user_session fails immediately with a permissions error, your
 * reseller account doesn't have the "Create User Session" ACL enabled -
 * that's a real access limit, not a bug, and check_wordpress.php's file
 * scan is the fallback in that case.
 */

define('WHM_HOST', getenv('WHM_HOST') ?: 'YOUR_SERVER_HOSTNAME');
define('WHM_PORT', getenv('WHM_PORT') ?: '2087');
define('WHM_USERNAME', getenv('WHM_USERNAME') ?: 'YOUR_WHM_USERNAME');
define('WHM_API_TOKEN', getenv('WHM_API_TOKEN') ?: 'YOUR_API_TOKEN');
define('WHM_SOURCE_LABEL', getenv('WHM_SOURCE_LABEL') ?: (WHM_HOST . ':' . WHM_USERNAME));
define('DB_PATH', __DIR__ . '/websites.sqlite');
define('RAW_JSON_LOG_PATH', __DIR__ . '/wp_toolkit_raw_' . WHM_SOURCE_LABEL . '.jsonl');

// ---------------------------------------------------------------------
// DB
// ---------------------------------------------------------------------
function get_db(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Drop the old provisional table from before the real schema was
    // confirmed - it used guessed field names and is superseded by
    // wp_installs_toolkit below.
    $pdo->exec("DROP TABLE IF EXISTS wp_installs_toolkit_items");

    // One row per account: whether the API call succeeded and the raw
    // response, so nothing is lost even from accounts the parse below
    // doesn't handle cleanly.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wp_installs_toolkit_raw (
            account       TEXT,
            whm_source    TEXT,
            success       INTEGER,
            error_message TEXT,
            raw_response  TEXT,
            checked_at    TEXT,
            PRIMARY KEY (account, whm_source)
        )
    ");

    // One row per WordPress installation. Most rows come from WP Toolkit
    // (data_source = 'wp_toolkit') with the full field set below. If WP
    // Toolkit reports zero installs for an account, a fallback file scan
    // runs (same logic as check_wordpress.php) and any installs it finds
    // are stored here too with data_source = 'file_scan_fallback' - those
    // rows only have path/domain/wp_version filled in, since a filesystem
    // scan can't see vulnerability/PHP-EOL/update data the way WP
    // Toolkit's API can.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wp_installs_toolkit (
            account                   TEXT,
            whm_source                TEXT,
            wpt_id                    INTEGER,
            title                     TEXT,
            site_url                  TEXT,
            wp_version                TEXT,
            path                      TEXT,
            domain_name               TEXT,
            alive                     INTEGER,
            infected                  INTEGER,
            unsupported               INTEGER,
            multisite                 INTEGER,
            plugins_with_updates      INTEGER,
            themes_with_updates       INTEGER,
            core_update_available     TEXT,
            vulnerable                INTEGER,
            vulnerability_risk_score  REAL,
            vulnerability_risk_rank   TEXT,
            php_version               TEXT,
            php_identifier            TEXT,
            php_unsupported           INTEGER,
            php_eoled                 INTEGER,
            security_status           TEXT,
            ssl_enabled               INTEGER,
            ssl_issuer                TEXT,
            backups_available         INTEGER,
            raw_json                  TEXT,
            checked_at                TEXT,
            PRIMARY KEY (account, wpt_id, whm_source)
        )
    ");

    // Non-destructive migration for anyone who already has this table from
    // before the fallback feature existed - add the column rather than
    // dropping the table, since it may already hold real vulnerability
    // data you don't want to lose.
    $columns = $pdo->query("PRAGMA table_info(wp_installs_toolkit)")->fetchAll(PDO::FETCH_ASSOC);
    $hasDataSource = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'data_source') {
            $hasDataSource = true;
            break;
        }
    }
    if (!$hasDataSource) {
        $pdo->exec("ALTER TABLE wp_installs_toolkit ADD COLUMN data_source TEXT DEFAULT 'wp_toolkit'");
    }

    return $pdo;
}

function clear_existing_source_data(PDO $pdo, string $source): void {
    $pdo->prepare("DELETE FROM wp_installs_toolkit_raw WHERE whm_source = :source")
        ->execute([':source' => $source]);
    $pdo->prepare("DELETE FROM wp_installs_toolkit WHERE whm_source = :source")
        ->execute([':source' => $source]);
}

// ---------------------------------------------------------------------
// WHM API HELPER (same pattern as the other scripts)
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

// ---------------------------------------------------------------------
// FILE-SCAN FALLBACK (ported from check_wordpress.php)
//
// Runs only when WP Toolkit reports zero installs for an account, as a
// safety net in case WP Toolkit itself isn't tracking an install that
// genuinely exists on disk. Rows found this way go into wp_installs_toolkit
// with data_source = 'file_scan_fallback' and only path/domain/version
// filled in - a filesystem scan can't see vulnerability/PHP-EOL/update
// data the way WP Toolkit's API can, so those fields stay null for these
// rows by design, not by mistake.
// ---------------------------------------------------------------------

function uapi_call(string $username, string $module, string $function, array $extraParams = []): array {
    $params = array_merge([
        'cpanel.user'     => $username,
        'cpanel.module'   => $module,
        'cpanel.function' => $function,
    ], $extraParams);

    $response = whm_api_call('uapi_cpanel', $params);
    return $response['data']['uapi'] ?? [];
}

/**
 * Returns a list of ['domain' => ..., 'domain_type' => ..., 'docroot' => ...
 * (absolute)] for every domain on this account that has a document root
 * (main + addon; parked domains alias the main domain's docroot so are
 * skipped).
 */
function get_docroots_for_account(string $username): array {
    $result = uapi_call($username, 'DomainInfo', 'domains_data', ['format' => 'hash']);
    $data = $result['data'] ?? [];

    $entries = [];

    $mainDomain = $data['main_domain'] ?? null;
    if ($mainDomain && !empty($mainDomain['documentroot'])) {
        $entries[] = ['domain' => $mainDomain['domain'] ?? null, 'domain_type' => 'main', 'docroot' => $mainDomain['documentroot']];
    }

    foreach ($data['addon_domains'] ?? [] as $addon) {
        if (!empty($addon['documentroot'])) {
            $entries[] = ['domain' => $addon['domain'] ?? null, 'domain_type' => 'addon', 'docroot' => $addon['documentroot']];
        }
    }

    return $entries;
}

// Same scan limits/exclusions as check_wordpress.php - kept identical so
// the fallback behaves consistently with the standalone scan script.
define('MAX_SCAN_DEPTH', (int) (getenv('WP_SCAN_MAX_DEPTH') ?: 2));
define('MAX_DIRS_PER_SITE', (int) (getenv('WP_SCAN_MAX_DIRS') ?: 150));
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
 * looking for wp-config.php. Identical logic to check_wordpress.php.
 */
function scan_for_wordpress_installs(string $username, string $rootPath): array {
    $found = [];
    $queue = [[$rootPath, 0]];
    $visited = 0;

    while (!empty($queue)) {
        [$path, $depth] = array_shift($queue);
        $visited++;

        if ($visited > MAX_DIRS_PER_SITE) {
            fwrite(STDERR, "      [scan limit] hit " . MAX_DIRS_PER_SITE . " directories under {$rootPath} - stopping early\n");
            break;
        }

        try {
            $result = uapi_call($username, 'Fileman', 'list_files', ['dir' => $path, 'types' => 'file|dir']);
        } catch (Throwable $e) {
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
            $found[] = ['docroot' => $path, 'wp_version' => fetch_wp_version_from_disk($username, $path)];
            continue;
        }

        if ($depth < MAX_SCAN_DEPTH) {
            foreach ($subdirs as $sub) {
                $queue[] = [rtrim($path, '/') . '/' . $sub, $depth + 1];
            }
        }

        usleep(80000);
    }

    return $found;
}

function fetch_wp_version_from_disk(string $username, string $installPath): ?string {
    try {
        $versionFileDir = rtrim($installPath, '/') . '/wp-includes';
        $content = uapi_call($username, 'Fileman', 'get_file_content', ['dir' => $versionFileDir, 'file' => 'version.php']);
        $fileContent = $content['data']['content'] ?? '';
        if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $fileContent, $m)) {
            return $m[1];
        }
    } catch (Throwable $e) {
        // Non-fatal - we still know WP is installed even without a version.
    }
    return null;
}

/**
 * Runs the fallback scan for one account and returns rows shaped to match
 * extract_installation_row()'s output, so both paths can be inserted with
 * the same code. WP-Toolkit-only fields (vulnerability, PHP EOL, updates,
 * etc.) are null here since a filesystem scan has no way to know them.
 */
function run_file_scan_fallback(string $username): array {
    $docroots = get_docroots_for_account($username);
    $rows = [];

    foreach ($docroots as $entry) {
        $installs = scan_for_wordpress_installs($username, $entry['docroot']);
        foreach ($installs as $install) {
            $rows[] = [
                'wpt_id'                   => null,
                'title'                    => null,
                'site_url'                 => null,
                'wp_version'               => $install['wp_version'],
                'path'                     => $install['docroot'],
                'domain_name'              => $entry['domain'],
                'alive'                    => null,
                'infected'                 => null,
                'unsupported'              => null,
                'multisite'                => null,
                'plugins_with_updates'     => null,
                'themes_with_updates'      => null,
                'core_update_available'    => null,
                'vulnerable'               => null,
                'vulnerability_risk_score' => null,
                'vulnerability_risk_rank'  => null,
                'php_version'              => null,
                'php_identifier'           => null,
                'php_unsupported'          => null,
                'php_eoled'                => null,
                'security_status'          => null,
                'ssl_enabled'              => null,
                'ssl_issuer'               => null,
                'backups_available'        => null,
                'raw_json'                 => null,
            ];
        }
    }

    return $rows;
}

// ---------------------------------------------------------------------
// CPANEL SESSION + WP TOOLKIT CALL
// ---------------------------------------------------------------------
/**
 * Mints a one-time cPanel login URL for the given account via WHM's
 * create_user_session. Throws if your reseller ACLs don't permit this.
 */
function create_cpanel_session(string $username): string {
    $response = whm_api_call('create_user_session', [
        'user'    => $username,
        'service' => 'cpaneld',
        'locale'  => 'en',
    ]);

    $result = $response['metadata']['result'] ?? 0;
    if ($result != 1) {
        $reason = $response['metadata']['reason'] ?? 'Unknown error';
        throw new RuntimeException("create_user_session failed: {$reason}");
    }

    $loginUrl = $response['data']['url'] ?? null;
    if ($loginUrl === null) {
        throw new RuntimeException('create_user_session succeeded but response was missing the login url');
    }

    return $loginUrl;
}

/**
 * "Visits" the one-time login URL WITHOUT letting cURL auto-follow the
 * redirect, so we can manually extract the cpsession cookie from the
 * Set-Cookie header and manually re-attach it when following the
 * redirect - this is the flow confirmed working in the Node.js version.
 * A cookie-jar + auto-follow-redirects approach was tried first but
 * proved less reliable for cPanel's specific login handshake.
 *
 * Returns the raw "cpsession=...;" cookie string to attach as a Cookie
 * header on all subsequent requests.
 */
function establish_session(string $loginUrl): string {
    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Failed to fetch login URL: {$err}");
    }
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerText = substr($response, 0, $headerSize);

    $cpsession = extract_cookie($headerText, 'cpsession');
    if ($cpsession === null) {
        throw new RuntimeException('Could not extract cpsession cookie from login response headers');
    }

    // Follow the redirect ourselves, manually attaching the cookie - this
    // is the step that actually activates the session server-side.
    $location = extract_header($headerText, 'Location');
    if ($location !== null) {
        $parts = parse_url($loginUrl);
        $base = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $activateUrl = str_starts_with($location, 'http') ? $location : $base . $location;

        $ch2 = curl_init($activateUrl);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ['Cookie: ' . $cpsession],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch2);
        curl_close($ch2);
    }

    return $cpsession;
}

function extract_cookie(string $headerText, string $cookieName): ?string {
    if (preg_match_all('/^Set-Cookie:\s*(.+)$/mi', $headerText, $matches)) {
        foreach ($matches[1] as $cookieLine) {
            $firstPart = trim(explode(';', $cookieLine)[0]);
            if (str_starts_with($firstPart, $cookieName . '=')) {
                return $firstPart;
            }
        }
    }
    return null;
}

function extract_header(string $headerText, string $headerName): ?string {
    if (preg_match('/^' . preg_quote($headerName, '/') . ':\s*(.+)$/mi', $headerText, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * Extracts the "cpsessXXXXXXXXXX" path segment from the login URL, the
 * same way the Node.js version does (urlObj.pathname.split('/').find(...))
 * rather than trusting create_user_session's cp_security_token field to
 * always match - keeping this consistent with the proven-working code.
 */
function extract_session_token(string $loginUrl): string {
    $path = parse_url($loginUrl, PHP_URL_PATH) ?? '';
    foreach (explode('/', $path) as $segment) {
        if (str_starts_with($segment, 'cpsess')) {
            return $segment;
        }
    }
    throw new RuntimeException("Could not find cpsess token in login URL path: {$loginUrl}");
}

/**
 * Calls WP Toolkit's installations endpoint using the manually-extracted
 * cpsession cookie (as an explicit Cookie header, matching the proven
 * Node.js approach) rather than a cURL cookie jar.
 */
function fetch_wp_toolkit_installations(string $loginUrl, string $cpsession): string {
    $parsed = parse_url($loginUrl);
    if (!$parsed || empty($parsed['host'])) {
        throw new RuntimeException("Could not parse host from login URL: {$loginUrl}");
    }

    $scheme = $parsed['scheme'] ?? 'https';
    $host = $parsed['host'];
    $port = $parsed['port'] ?? 2083;
    $sessionToken = extract_session_token($loginUrl);

    $endpoint = "{$scheme}://{$host}:{$port}/{$sessionToken}/3rdparty/wpt/index.php/v1/installations";

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'X-Wpt-Ui: true',
            'Cookie: ' . $cpsession,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("cURL error calling WP Toolkit endpoint: {$curlError}");
    }
    if ($httpCode !== 200) {
        throw new RuntimeException("WP Toolkit endpoint returned HTTP {$httpCode}: " . substr($body, 0, 300));
    }

    return $body;
}

/**
 * Safely reads a nested value from an array via a chain of keys, e.g.
 * dig($item, 'features', 'php', 'handler', 'version'). Returns null if any
 * key in the chain is missing rather than throwing.
 */
function dig(array $arr, string ...$keys): mixed {
    $cur = $arr;
    foreach ($keys as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) {
            return null;
        }
        $cur = $cur[$k];
    }
    return $cur;
}

function bool_to_int(mixed $v): ?int {
    if ($v === null) {
        return null;
    }
    return $v ? 1 : 0;
}

/**
 * Extracts one normalized row from a single WP Toolkit installation
 * object. Field mapping confirmed against real captured output (see
 * conversation history) - not a guess.
 */
function extract_installation_row(array $item): array {
    return [
        'wpt_id'                   => $item['id'] ?? null,
        'title'                    => $item['title'] ?? null,
        'site_url'                 => $item['url'] ?? null,
        'wp_version'               => $item['version'] ?? null,
        'path'                     => $item['path'] ?? null,
        'domain_name'              => dig($item, 'domain', 'name'),
        'alive'                    => bool_to_int(dig($item, 'status', 'alive')),
        'infected'                 => bool_to_int(dig($item, 'status', 'infected')),
        'unsupported'              => bool_to_int(dig($item, 'status', 'unsupported')),
        'multisite'                => bool_to_int(dig($item, 'status', 'multisite')),
        'plugins_with_updates'     => dig($item, 'features', 'updates', 'amountOfPluginsWithUpdates'),
        'themes_with_updates'      => dig($item, 'features', 'updates', 'amountOfThemesWithUpdates'),
        'core_update_available'    => dig($item, 'features', 'updates', 'availableVersion'),
        'vulnerable'               => bool_to_int(dig($item, 'features', 'vulnerability', 'vulnerable')),
        'vulnerability_risk_score' => dig($item, 'features', 'vulnerability', 'securityRiskScore'),
        'vulnerability_risk_rank'  => dig($item, 'features', 'vulnerability', 'highestActiveVulnerabilityRiskRank'),
        'php_version'              => dig($item, 'features', 'php', 'handler', 'version'),
        'php_identifier'           => dig($item, 'features', 'php', 'handler', 'identifier'),
        'php_unsupported'          => bool_to_int(dig($item, 'features', 'php', 'unsupported')),
        'php_eoled'                => bool_to_int(dig($item, 'features', 'php', 'eoled')),
        'security_status'          => dig($item, 'features', 'security', 'status'),
        'ssl_enabled'              => bool_to_int(dig($item, 'domain', 'ssl', 'enabled')),
        'ssl_issuer'               => dig($item, 'domain', 'ssl', 'certificate', 'issuerName'),
        'backups_available'        => bool_to_int(dig($item, 'features', 'backups', 'panelBackupsAvailable')),
        'raw_json'                 => json_encode($item),
    ];
}

/**
 * Parses the raw WP Toolkit response body into a list of normalized
 * installation rows. The endpoint returns a plain top-level array of
 * installation objects (confirmed from real output) - the data/
 * installations wrapper checks below are just defensive fallbacks in case
 * a future WP Toolkit version wraps the response differently.
 */
function parse_wp_toolkit_response(string $rawBody): array {
    $decoded = json_decode($rawBody, true);
    if ($decoded === null) {
        return [];
    }

    $items = $decoded;
    if (isset($decoded['data']) && is_array($decoded['data'])) {
        $items = $decoded['data'];
    } elseif (isset($decoded['installations']) && is_array($decoded['installations'])) {
        $items = $decoded['installations'];
    }

    if (!is_array($items)) {
        return [];
    }

    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $rows[] = extract_installation_row($item);
    }

    return $rows;
}

// ---------------------------------------------------------------------
// RUN
// ---------------------------------------------------------------------
function run(): void {
    $pdo = get_db();

    $accounts = $pdo->prepare("SELECT username FROM whm_accounts WHERE whm_source = :source");
    $accounts->execute([':source' => WHM_SOURCE_LABEL]);
    $accounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

    if (count($accounts) === 0) {
        fwrite(STDERR, "No accounts found for source '" . WHM_SOURCE_LABEL . "' in whm_accounts - run sync_whm_accounts.php for this source first.\n");
        exit(1);
    }

    echo "Querying WP Toolkit for " . count($accounts) . " accounts (source: " . WHM_SOURCE_LABEL . ")...\n";

    clear_existing_source_data($pdo, WHM_SOURCE_LABEL);

    $insertRaw = $pdo->prepare("
        INSERT INTO wp_installs_toolkit_raw (account, whm_source, success, error_message, raw_response, checked_at)
        VALUES (:account, :whm_source, :success, :error_message, :raw_response, :checked_at)
    ");
    $insertItem = $pdo->prepare("
        INSERT INTO wp_installs_toolkit
            (account, whm_source, wpt_id, title, site_url, wp_version, path, domain_name,
             alive, infected, unsupported, multisite,
             plugins_with_updates, themes_with_updates, core_update_available,
             vulnerable, vulnerability_risk_score, vulnerability_risk_rank,
             php_version, php_identifier, php_unsupported, php_eoled,
             security_status, ssl_enabled, ssl_issuer, backups_available,
             raw_json, data_source, checked_at)
        VALUES
            (:account, :whm_source, :wpt_id, :title, :site_url, :wp_version, :path, :domain_name,
             :alive, :infected, :unsupported, :multisite,
             :plugins_with_updates, :themes_with_updates, :core_update_available,
             :vulnerable, :vulnerability_risk_score, :vulnerability_risk_rank,
             :php_version, :php_identifier, :php_unsupported, :php_eoled,
             :security_status, :ssl_enabled, :ssl_issuer, :backups_available,
             :raw_json, :data_source, :checked_at)
    ");

    $rawLog = fopen(RAW_JSON_LOG_PATH, 'w');

    $consecutiveFailures = 0;
    $totalSuccess = 0;
    $totalInstalls = 0;
    $totalFallbackFound = 0;
    $i = 0;
    $syncedAt = date('c');

    foreach ($accounts as $acct) {
        $i++;
        $username = $acct['username'];

        try {
            $loginUrl = create_cpanel_session($username);
            $cpsession = establish_session($loginUrl);
            $rawBody = fetch_wp_toolkit_installations($loginUrl, $cpsession);

            fwrite($rawLog, json_encode(['account' => $username, 'response' => json_decode($rawBody, true) ?? $rawBody]) . "\n");

            $insertRaw->execute([
                ':account'       => $username,
                ':whm_source'    => WHM_SOURCE_LABEL,
                ':success'       => 1,
                ':error_message' => null,
                ':raw_response'  => $rawBody,
                ':checked_at'    => $syncedAt,
            ]);

            $items = parse_wp_toolkit_response($rawBody);
            foreach ($items as $item) {
                $insertItem->execute([
                    ':account'                  => $username,
                    ':whm_source'               => WHM_SOURCE_LABEL,
                    ':wpt_id'                   => $item['wpt_id'],
                    ':title'                    => $item['title'],
                    ':site_url'                 => $item['site_url'],
                    ':wp_version'               => $item['wp_version'],
                    ':path'                     => $item['path'],
                    ':domain_name'              => $item['domain_name'],
                    ':alive'                    => $item['alive'],
                    ':infected'                 => $item['infected'],
                    ':unsupported'              => $item['unsupported'],
                    ':multisite'                => $item['multisite'],
                    ':plugins_with_updates'     => $item['plugins_with_updates'],
                    ':themes_with_updates'      => $item['themes_with_updates'],
                    ':core_update_available'    => $item['core_update_available'],
                    ':vulnerable'               => $item['vulnerable'],
                    ':vulnerability_risk_score' => $item['vulnerability_risk_score'],
                    ':vulnerability_risk_rank'  => $item['vulnerability_risk_rank'],
                    ':php_version'              => $item['php_version'],
                    ':php_identifier'           => $item['php_identifier'],
                    ':php_unsupported'          => $item['php_unsupported'],
                    ':php_eoled'                => $item['php_eoled'],
                    ':security_status'          => $item['security_status'],
                    ':ssl_enabled'              => $item['ssl_enabled'],
                    ':ssl_issuer'               => $item['ssl_issuer'],
                    ':backups_available'        => $item['backups_available'],
                    ':raw_json'                 => $item['raw_json'],
                    ':data_source'              => 'wp_toolkit',
                    ':checked_at'               => $syncedAt,
                ]);
                $totalInstalls++;
            }

            // WP Toolkit reported nothing for this account - fall back to
            // a direct filesystem scan just to make sure nothing's being
            // missed (e.g. an install WP Toolkit itself isn't tracking).
            if (count($items) === 0) {
                echo "    -> 0 installs from WP Toolkit, running file-scan fallback for {$username}...\n";
                try {
                    $fallbackRows = run_file_scan_fallback($username);
                } catch (Throwable $e) {
                    fwrite(STDERR, "    [{$username}] File-scan fallback failed: " . $e->getMessage() . "\n");
                    $fallbackRows = [];
                }

                foreach ($fallbackRows as $item) {
                    $insertItem->execute([
                        ':account'                  => $username,
                        ':whm_source'               => WHM_SOURCE_LABEL,
                        ':wpt_id'                   => $item['wpt_id'],
                        ':title'                    => $item['title'],
                        ':site_url'                 => $item['site_url'],
                        ':wp_version'               => $item['wp_version'],
                        ':path'                     => $item['path'],
                        ':domain_name'              => $item['domain_name'],
                        ':alive'                    => $item['alive'],
                        ':infected'                 => $item['infected'],
                        ':unsupported'              => $item['unsupported'],
                        ':multisite'                => $item['multisite'],
                        ':plugins_with_updates'     => $item['plugins_with_updates'],
                        ':themes_with_updates'      => $item['themes_with_updates'],
                        ':core_update_available'    => $item['core_update_available'],
                        ':vulnerable'               => $item['vulnerable'],
                        ':vulnerability_risk_score' => $item['vulnerability_risk_score'],
                        ':vulnerability_risk_rank'  => $item['vulnerability_risk_rank'],
                        ':php_version'              => $item['php_version'],
                        ':php_identifier'           => $item['php_identifier'],
                        ':php_unsupported'          => $item['php_unsupported'],
                        ':php_eoled'                => $item['php_eoled'],
                        ':security_status'          => $item['security_status'],
                        ':ssl_enabled'              => $item['ssl_enabled'],
                        ':ssl_issuer'               => $item['ssl_issuer'],
                        ':backups_available'        => $item['backups_available'],
                        ':raw_json'                 => $item['raw_json'],
                        ':data_source'              => 'file_scan_fallback',
                        ':checked_at'               => $syncedAt,
                    ]);
                    $totalInstalls++;
                    $totalFallbackFound++;
                }

                if (count($fallbackRows) > 0) {
                    echo "    -> file-scan fallback found " . count($fallbackRows) . " install(s) WP Toolkit missed!\n";
                }
            }

            $totalSuccess++;
            $consecutiveFailures = 0;
            echo "  [{$i}/" . count($accounts) . "] {$username}: " . count($items) . " installation(s)\n";
        } catch (Throwable $e) {
            $consecutiveFailures++;
            $insertRaw->execute([
                ':account'       => $username,
                ':whm_source'    => WHM_SOURCE_LABEL,
                ':success'       => 0,
                ':error_message' => $e->getMessage(),
                ':raw_response'  => null,
                ':checked_at'    => $syncedAt,
            ]);
            fwrite(STDERR, "  [{$i}/" . count($accounts) . "] {$username}: FAILED - " . $e->getMessage() . "\n");

            // Fail fast rather than grinding through hundreds of accounts
            // hitting the same wall - a permissions/ACL problem will fail
            // identically every time.
            if ($consecutiveFailures >= 3) {
                fwrite(STDERR, "\n" . $consecutiveFailures . " consecutive failures - stopping early.\n");
                fwrite(STDERR, "This usually means your reseller account doesn't have the 'Create User Session' ACL enabled, or WP Toolkit isn't installed/enabled for this account.\n");
                fwrite(STDERR, "check_wordpress.php (the file-scanning approach) remains a working fallback regardless of this ACL.\n");
                break;
            }
        }

        usleep(300000); // 0.3s - this does 2-3 HTTP round trips per account, be gentler than the other scripts
    }

    fclose($rawLog);

    // CSV export - the columns that actually matter for a quick scan.
    $csvPath = __DIR__ . '/wp_toolkit_report_' . WHM_SOURCE_LABEL . '.csv';
    $fh = fopen($csvPath, 'w');
    fputcsv($fh, [
        'domain',
        'account',
        'data_source',
        'wp_version',
        'core_update_available',
        'plugins_with_updates',
        'themes_with_updates',
        'vulnerable',
        'vulnerability_risk_rank',
        'infected',
        'php_version',
        'php_eoled',
        'php_unsupported',
        'security_status',
        'ssl_issuer',
        'path',
    ], ',', '"', '\\');
    $rows = $pdo->prepare("SELECT * FROM wp_installs_toolkit WHERE whm_source = :source ORDER BY vulnerable DESC, php_eoled DESC, domain_name");
    $rows->execute([':source' => WHM_SOURCE_LABEL]);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($fh, [
            $r['domain_name'],
            $r['account'],
            $r['data_source'],
            $r['wp_version'],
            $r['core_update_available'],
            $r['plugins_with_updates'],
            $r['themes_with_updates'],
            $r['vulnerable'] ? 'yes' : 'no',
            $r['vulnerability_risk_rank'],
            $r['infected'] ? 'yes' : 'no',
            $r['php_version'],
            $r['php_eoled'] ? 'yes' : 'no',
            $r['php_unsupported'] ? 'yes' : 'no',
            $r['security_status'],
            $r['ssl_issuer'],
            $r['path'],
        ], ',', '"', '\\');
    }
    fclose($fh);

    // Pull the numbers that matter for the console summary.
    $countStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN vulnerable = 1 THEN 1 ELSE 0 END) as vulnerable_count,
            SUM(CASE WHEN infected = 1 THEN 1 ELSE 0 END) as infected_count,
            SUM(CASE WHEN php_eoled = 1 THEN 1 ELSE 0 END) as php_eoled_count,
            SUM(CASE WHEN php_unsupported = 1 THEN 1 ELSE 0 END) as php_unsupported_count,
            SUM(CASE WHEN core_update_available IS NOT NULL THEN 1 ELSE 0 END) as core_outdated_count,
            SUM(CASE WHEN plugins_with_updates > 0 THEN 1 ELSE 0 END) as plugins_outdated_count,
            SUM(CASE WHEN unsupported = 1 THEN 1 ELSE 0 END) as unsupported_count,
            SUM(CASE WHEN data_source = 'file_scan_fallback' THEN 1 ELSE 0 END) as fallback_count
        FROM wp_installs_toolkit WHERE whm_source = :source
    ");
    $countStmt->execute([':source' => WHM_SOURCE_LABEL]);
    $stats = $countStmt->fetch(PDO::FETCH_ASSOC);

    echo "\n=== WP Toolkit Query Summary (source: " . WHM_SOURCE_LABEL . ") ===\n";
    echo "Accounts successfully queried: {$totalSuccess}/" . count($accounts) . "\n";
    echo "Total WordPress installations found: {$stats['total']}\n";
    echo "  - via WP Toolkit:      " . ($stats['total'] - $stats['fallback_count']) . "\n";
    echo "  - via file-scan fallback: {$stats['fallback_count']} (WP Toolkit reported 0 for these accounts)\n\n";
    echo "Infected (WP Toolkit flagged malware): {$stats['infected_count']}\n";
    echo "Have an active vulnerability:          {$stats['vulnerable_count']}\n";
    echo "Running an EOL PHP version:            {$stats['php_eoled_count']}\n";
    echo "Running an unsupported PHP version:     {$stats['php_unsupported_count']}\n";
    echo "WP core update available:              {$stats['core_outdated_count']}\n";
    echo "Have plugin updates pending:           {$stats['plugins_outdated_count']}\n";
    echo "Flagged 'unsupported' by WP Toolkit:   {$stats['unsupported_count']}\n";

    if ($stats['infected_count'] > 0) {
        echo "\n*** {$stats['infected_count']} infected site(s) - see CSV, sorted to the top ***\n";
    }
    if ($stats['fallback_count'] > 0) {
        echo "\n*** {$stats['fallback_count']} install(s) found only via file-scan fallback - WP Toolkit wasn't tracking these. Worth a manual look. ***\n";
    }

    echo "\nFull results written to {$csvPath}\n";
    echo "Raw responses (one JSON object per line) written to " . RAW_JSON_LOG_PATH . "\n";
    echo "Also queryable in websites.sqlite -> wp_installs_toolkit_raw (full raw response per account)\n";
    echo "                                  -> wp_installs_toolkit (parsed installation rows, see data_source column)\n";
}

try {
    run();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
