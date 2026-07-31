<?php

/**
 * check_error_logs.php
 *
 * "Check cPanels for Error.logs" - the fourth and final item on your
 * original list. For every account belonging to one WHM source, this:
 *
 *   1. Calls DomainInfo::domains_data (format=hash) to get the real
 *      document root for the main domain and every addon domain - same
 *      approach as check_wordpress.php.
 *   2. Checks each docroot for a standard PHP error_log file via
 *      Fileman::list_files, recording its size (and modified time, if
 *      the API returns one).
 *   3. If the file is non-empty and under ERRLOG_MAX_FETCH_BYTES (default
 *      1MB), fetches its content and extracts the last non-empty line,
 *      plus a short tail excerpt for more context. Larger files are
 *      flagged as "too large to fetch" rather than downloaded in full -
 *      a 50MB error_log doesn't need to cross the wire just to read one
 *      line, and repeatedly doing that across 400+ sites would be slow.
 *   4. Best-effort parses the standard PHP error_log timestamp format
 *      ([dd-Mon-yyyy hh:mm:ss UTC]) off the last line, so the report can
 *      tell you "last error was 3 days ago" vs "last error was in 2019" -
 *      that distinction matters a lot more than just knowing errors exist.
 *
 * This only checks for error_log directly in each docroot (the standard
 * PHP default location) - not subdirectories. Subfolder WordPress installs
 * (see check_wordpress.php) can each have their own error_log too; that's
 * out of scope here to avoid doubling the API calls this script already
 * makes, but the same recursive-scan approach could be added later if it
 * turns out to matter in practice.
 *
 * Like the other per-source scripts, run this once per reseller account.
 * It reads the account list from your already-synced whm_accounts table.
 *
 * Usage:
 *   WHM_SOURCE_LABEL=shock-1 WHM_HOST=... WHM_USERNAME=... WHM_API_TOKEN=... php check_error_logs.php
 */

define('WHM_HOST', getenv('WHM_HOST') ?: 'YOUR_SERVER_HOSTNAME');
define('WHM_PORT', getenv('WHM_PORT') ?: '2087');
define('WHM_USERNAME', getenv('WHM_USERNAME') ?: 'YOUR_WHM_USERNAME');
define('WHM_API_TOKEN', getenv('WHM_API_TOKEN') ?: 'YOUR_API_TOKEN');
define('WHM_SOURCE_LABEL', getenv('WHM_SOURCE_LABEL') ?: (WHM_HOST . ':' . WHM_USERNAME));
define('DB_PATH', __DIR__ . '/websites.sqlite');

// Don't download error_log content past this size - just flag it as too
// large and let the size column itself signal "this needs a manual look".
define('MAX_FETCH_BYTES', (int) (getenv('ERRLOG_MAX_FETCH_BYTES') ?: 1048576)); // 1MB

// How much of the end of the file to keep as an excerpt (full multi-line
// stack traces are more useful than a single truncated line).
define('TAIL_EXCERPT_CHARS', (int) (getenv('ERRLOG_TAIL_CHARS') ?: 2000));

// ---------------------------------------------------------------------
// DB
// ---------------------------------------------------------------------
function get_db(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS error_logs (
            domain              TEXT,
            whm_source          TEXT,
            account             TEXT,
            domain_type         TEXT,
            docroot             TEXT,
            log_exists          INTEGER,
            size_bytes          INTEGER,
            mtime               TEXT,
            too_large_to_fetch  INTEGER,
            last_error_line     TEXT,
            last_error_at       TEXT,
            tail_excerpt        TEXT,
            checked_at          TEXT,
            PRIMARY KEY (domain, docroot, whm_source)
        )
    ");

    return $pdo;
}

function clear_existing_source_data(PDO $pdo, string $source): void {
    $pdo->prepare("DELETE FROM error_logs WHERE whm_source = :source")
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
// DOCROOT DISCOVERY (same approach as check_wordpress.php)
// ---------------------------------------------------------------------
function get_docroots_for_account(string $username): array {
    $result = uapi_call($username, 'DomainInfo', 'domains_data', ['format' => 'hash']);
    $data = $result['data'] ?? [];

    $entries = [];

    $mainDomain = $data['main_domain'] ?? null;
    if ($mainDomain && !empty($mainDomain['documentroot'])) {
        $entries[] = [
            'domain'      => $mainDomain['domain'] ?? null,
            'domain_type' => 'main',
            'docroot'     => $mainDomain['documentroot'],
        ];
    }

    foreach ($data['addon_domains'] ?? [] as $addon) {
        if (!empty($addon['documentroot'])) {
            $entries[] = [
                'domain'      => $addon['domain'] ?? null,
                'domain_type' => 'addon',
                'docroot'     => $addon['documentroot'],
            ];
        }
    }

    return $entries;
}

// ---------------------------------------------------------------------
// ERROR LOG CHECK
// ---------------------------------------------------------------------
/**
 * Checks one docroot for an error_log file. Returns null if no such file
 * exists (or is empty), otherwise a row describing what was found.
 */
function check_error_log(string $username, string $docroot): ?array {
    $result = uapi_call($username, 'Fileman', 'list_files', [
        'dir'   => $docroot,
        'types' => 'file',
    ]);

    $files = $result['data'] ?? [];
    if (!is_array($files)) {
        return null;
    }

    $logEntry = null;
    foreach ($files as $f) {
        if (($f['file'] ?? '') === 'error_log') {
            $logEntry = $f;
            break;
        }
    }

    if ($logEntry === null) {
        return null;
    }

    $size = (int) ($logEntry['size'] ?? 0);
    $mtime = $logEntry['mtime'] ?? null; // may be a unix timestamp or absent depending on server version

    $row = [
        'log_exists'         => 1,
        'size_bytes'         => $size,
        'mtime'              => $mtime ? date('c', (int) $mtime) : null,
        'too_large_to_fetch' => 0,
        'last_error_line'    => null,
        'last_error_at'      => null,
        'tail_excerpt'       => null,
    ];

    if ($size === 0) {
        return $row;
    }

    if ($size > MAX_FETCH_BYTES) {
        $row['too_large_to_fetch'] = 1;
        return $row;
    }

    try {
        $content = uapi_call($username, 'Fileman', 'get_file_content', [
            'dir'  => $docroot,
            'file' => 'error_log',
        ]);
        $text = $content['data']['content'] ?? '';
    } catch (Throwable $e) {
        // Couldn't fetch content (permissions, transient error, etc.) -
        // we still know the file exists and its size, just not the tail.
        return $row;
    }

    $text = rtrim($text);
    if ($text === '') {
        return $row;
    }

    $row['tail_excerpt'] = mb_substr($text, -1 * TAIL_EXCERPT_CHARS);

    $lines = explode("\n", $text);
    $lastLine = trim(end($lines));
    $row['last_error_line'] = $lastLine;
    $row['last_error_at'] = parse_php_error_timestamp($lastLine);

    return $row;
}

/**
 * Best-effort parse of PHP's standard error_log timestamp format:
 * "[dd-Mon-yyyy hh:mm:ss TZ] PHP ..." e.g. "[15-Jul-2026 03:22:41 UTC]".
 * Returns an ISO 8601 string, or null if the line doesn't match (PHP
 * version/config differences mean this isn't guaranteed to always match).
 */
function parse_php_error_timestamp(string $line): ?string {
    if (preg_match('/\[(\d{1,2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}) ([A-Za-z]+)\]/', $line, $m)) {
        $timestamp = strtotime($m[1] . ' ' . $m[2]);
        if ($timestamp !== false) {
            return date('c', $timestamp);
        }
    }
    return null;
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

    echo "Checking error logs for " . count($accounts) . " accounts (source: " . WHM_SOURCE_LABEL . ")...\n";
    echo "Files over " . number_format(MAX_FETCH_BYTES / 1048576, 1) . "MB will be flagged but not downloaded (set ERRLOG_MAX_FETCH_BYTES to change).\n";

    clear_existing_source_data($pdo, WHM_SOURCE_LABEL);

    $insert = $pdo->prepare("
        INSERT INTO error_logs
            (domain, whm_source, account, domain_type, docroot,
             log_exists, size_bytes, mtime, too_large_to_fetch,
             last_error_line, last_error_at, tail_excerpt, checked_at)
        VALUES
            (:domain, :whm_source, :account, :domain_type, :docroot,
             :log_exists, :size_bytes, :mtime, :too_large_to_fetch,
             :last_error_line, :last_error_at, :tail_excerpt, :checked_at)
    ");

    $totalChecked = 0;
    $totalWithErrors = 0;
    $totalTooLarge = 0;
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

        foreach ($docroots as $entry) {
            $totalChecked++;

            try {
                $result = check_error_log($username, $entry['docroot']);
            } catch (Throwable $e) {
                fwrite(STDERR, "  [{$username}] Error log check failed for {$entry['domain']}: " . $e->getMessage() . "\n");
                $errorCount++;
                continue;
            }

            if ($result === null) {
                // No error_log file at all - nothing to record. Skip
                // inserting a row so the table only contains sites that
                // actually have a log, rather than 400+ mostly-empty rows.
                continue;
            }

            $insert->execute([
                ':domain'             => $entry['domain'],
                ':whm_source'         => WHM_SOURCE_LABEL,
                ':account'            => $username,
                ':domain_type'        => $entry['domain_type'],
                ':docroot'            => $entry['docroot'],
                ':log_exists'         => $result['log_exists'],
                ':size_bytes'         => $result['size_bytes'],
                ':mtime'              => $result['mtime'],
                ':too_large_to_fetch' => $result['too_large_to_fetch'],
                ':last_error_line'    => $result['last_error_line'],
                ':last_error_at'      => $result['last_error_at'],
                ':tail_excerpt'       => $result['tail_excerpt'],
                ':checked_at'         => $syncedAt,
            ]);

            if ($result['size_bytes'] > 0) {
                $totalWithErrors++;
            }
            if ($result['too_large_to_fetch']) {
                $totalTooLarge++;
            }

            usleep(80000);
        }

        if ($i % 25 === 0) {
            echo "  ...processed {$i}/" . count($accounts) . " accounts\n";
        }

        usleep(80000);
    }

    // CSV export, largest files first - the ones most worth looking at.
    $csvPath = __DIR__ . '/error_log_report_' . WHM_SOURCE_LABEL . '.csv';
    $fh = fopen($csvPath, 'w');
    fputcsv($fh, [
        'domain',
        'account',
        'size_bytes',
        'size_human',
        'too_large_to_fetch',
        'last_error_at',
        'last_error_line',
        'docroot',
    ], ',', '"', '\\');
    $rows = $pdo->prepare("SELECT * FROM error_logs WHERE whm_source = :source AND size_bytes > 0 ORDER BY size_bytes DESC");
    $rows->execute([':source' => WHM_SOURCE_LABEL]);
    $allRows = $rows->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allRows as $r) {
        fputcsv($fh, [
            $r['domain'],
            $r['account'],
            $r['size_bytes'],
            format_bytes((int) $r['size_bytes']),
            $r['too_large_to_fetch'] ? 'yes' : 'no',
            $r['last_error_at'],
            $r['last_error_line'],
            $r['docroot'],
        ], ',', '"', '\\');
    }
    fclose($fh);

    echo "\n=== Error Log Summary (source: " . WHM_SOURCE_LABEL . ") ===\n";
    echo "Docroots checked: {$totalChecked}\n";
    echo "Have a non-empty error_log: {$totalWithErrors}\n";
    echo "Too large to fetch content (see size only): {$totalTooLarge}\n";
    echo "Errors during check: {$errorCount}\n";

    echo "\n--- Largest error logs ---\n";
    foreach (array_slice($allRows, 0, 15) as $r) {
        $recency = $r['last_error_at'] ? ' - last error ' . human_time_diff($r['last_error_at']) : '';
        echo "  {$r['domain']}  " . format_bytes((int) $r['size_bytes']) . "{$recency}\n";
    }
    if (count($allRows) > 15) {
        echo "  ... and " . (count($allRows) - 15) . " more, see CSV for full list\n";
    }

    echo "\nFull results written to {$csvPath}\n";
    echo "Also queryable in websites.sqlite -> error_logs table\n";
}

function format_bytes(int $bytes): string {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . 'MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . 'KB';
    }
    return $bytes . 'B';
}

function human_time_diff(string $isoDate): string {
    $diff = time() - strtotime($isoDate);
    if ($diff < 0) {
        return 'just now';
    }
    $days = intdiv($diff, 86400);
    if ($days > 0) {
        return "{$days}d ago";
    }
    $hours = intdiv($diff, 3600);
    if ($hours > 0) {
        return "{$hours}h ago";
    }
    $minutes = intdiv($diff, 60);
    return "{$minutes}m ago";
}

try {
    run();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
