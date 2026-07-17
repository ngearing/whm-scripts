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
 * IMPORTANT: The exact JSON shape WP Toolkit returns isn't publicly
 * documented, so this script stores the raw response for every account
 * AND attempts a best-effort parse using common field name guesses. Run
 * this once, check wp_toolkit_raw.json / the wp_installs_toolkit_items
 * table, and tell me what the real structure looks like so the parsing
 * can be corrected precisely rather than guessed at.
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

    // One row per account: whether the API call succeeded and the raw
    // response, so nothing is lost even if the best-effort parse below
    // doesn't match WP Toolkit's actual field names.
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

    // Best-effort parsed rows, one per installation WP Toolkit reports for
    // an account. Field extraction uses several guessed key names - treat
    // this table as provisional until confirmed against real output.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wp_installs_toolkit_items (
            account       TEXT,
            whm_source    TEXT,
            item_index    INTEGER,
            path          TEXT,
            wp_version    TEXT,
            site_url      TEXT,
            status        TEXT,
            raw_item      TEXT,
            checked_at    TEXT,
            PRIMARY KEY (account, whm_source, item_index)
        )
    ");

    return $pdo;
}

function clear_existing_source_data(PDO $pdo, string $source): void {
    $pdo->prepare("DELETE FROM wp_installs_toolkit_raw WHERE whm_source = :source")
        ->execute([':source' => $source]);
    $pdo->prepare("DELETE FROM wp_installs_toolkit_items WHERE whm_source = :source")
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
 * WP Toolkit's real field values sometimes turn out to be nested
 * arrays/objects rather than plain strings (that's exactly the kind of
 * schema mismatch this best-effort parser is built to survive). PDO can't
 * bind an array directly, so anything non-scalar gets JSON-encoded
 * instead of causing an "Array to string conversion" warning and a
 * silently wrong bound value.
 */
function to_bindable(mixed $value): ?string {
    if ($value === null || is_scalar($value)) {
        return $value;
    }
    return json_encode($value);
}

/**
 * Best-effort extraction of installation records from the raw JSON body.
 * WP Toolkit's exact schema isn't publicly documented, so this tries a
 * handful of plausible key names. Returns an array of normalized rows;
 * falls back to storing the raw item untouched if none of the guessed
 * keys match, so nothing is silently dropped.
 */
function parse_wp_toolkit_response(string $rawBody): array {
    $decoded = json_decode($rawBody, true);
    if ($decoded === null) {
        return [];
    }

    // The installations list might be the top-level array, or nested
    // under a common wrapper key - try a few possibilities.
    $items = $decoded;
    if (isset($decoded['data']) && is_array($decoded['data'])) {
        $items = $decoded['data'];
    } elseif (isset($decoded['installations']) && is_array($decoded['installations'])) {
        $items = $decoded['installations'];
    }

    if (!is_array($items) || (!empty($items) && !is_int(array_key_first($items)))) {
        // Not a plain list - bail out, let the raw response speak for itself.
        return [];
    }

    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $rows[] = [
            'path'       => to_bindable($item['path'] ?? $item['documentRoot'] ?? $item['installationPath'] ?? null),
            'wp_version' => to_bindable($item['version'] ?? $item['wpVersion'] ?? $item['wp_version'] ?? null),
            'site_url'   => to_bindable($item['url'] ?? $item['siteUrl'] ?? $item['domain'] ?? null),
            'status'     => to_bindable($item['status'] ?? $item['state'] ?? $item['updateStatus'] ?? null),
            'raw_item'   => json_encode($item),
        ];
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
        INSERT INTO wp_installs_toolkit_items (account, whm_source, item_index, path, wp_version, site_url, status, raw_item, checked_at)
        VALUES (:account, :whm_source, :item_index, :path, :wp_version, :site_url, :status, :raw_item, :checked_at)
    ");

    $rawLog = fopen(RAW_JSON_LOG_PATH, 'w');

    $consecutiveFailures = 0;
    $totalSuccess = 0;
    $totalInstalls = 0;
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
            foreach ($items as $idx => $item) {
                $insertItem->execute([
                    ':account'    => $username,
                    ':whm_source' => WHM_SOURCE_LABEL,
                    ':item_index' => $idx,
                    ':path'       => $item['path'],
                    ':wp_version' => $item['wp_version'],
                    ':site_url'   => $item['site_url'],
                    ':status'     => $item['status'],
                    ':raw_item'   => $item['raw_item'],
                    ':checked_at' => $syncedAt,
                ]);
                $totalInstalls++;
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

    echo "\n=== WP Toolkit Query Summary (source: " . WHM_SOURCE_LABEL . ") ===\n";
    echo "Accounts successfully queried: {$totalSuccess}/" . count($accounts) . "\n";
    echo "Total installations reported: {$totalInstalls}\n";
    echo "\nRaw responses (one JSON object per line) written to " . RAW_JSON_LOG_PATH . "\n";
    echo "Also queryable in websites.sqlite -> wp_installs_toolkit_raw (full raw response per account)\n";
    echo "                                  -> wp_installs_toolkit_items (best-effort parsed rows)\n";
    echo "\nIMPORTANT: field parsing (path/version/status) is a best guess at WP Toolkit's schema.\n";
    echo "Check " . RAW_JSON_LOG_PATH . " and share a sample so the parsing can be corrected precisely.\n";
}

try {
    run();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
