<?php

/**
 * check_woocommerce.php
 *
 * Reads every confirmed WordPress install from wp_installs (populated by
 * check_wordpress.php's file-scan) and checks each one's
 * wp-content/plugins/woocommerce folder via the same uapi_cpanel ->
 * Fileman calls the scanner already uses, pulling the version out of
 * woocommerce.php's plugin header.
 *
 * This is a presence + version check based on files on disk, not an
 * "is it active" check - that lives in wp_options.active_plugins in the
 * site's database, which this script has no DB credentials to query. A
 * site with WooCommerce installed but deactivated will still show up
 * here with a version.
 *
 * Usage:
 *   WHM_SOURCE_LABEL=shock-1 WHM_HOST=... WHM_USERNAME=... WHM_API_TOKEN=... php check_woocommerce.php
 *
 * Requires wp_installs to already be populated for this source - run
 * check_wordpress.php first.
 */

require __DIR__ . '/bootstrap.php';
define_whm_config();
define('CSV_PATH', output_path('woocommerce_report_' . safe_filename_part(WHM_SOURCE_LABEL) . '.csv'));

// ---------------------------------------------------------------------
// DB
// ---------------------------------------------------------------------
function get_db(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wp_installs_woocommerce (
            domain           TEXT,
            whm_source       TEXT,
            account          TEXT,
            docroot          TEXT,
            has_woocommerce  INTEGER,
            wc_version       TEXT,
            check_error      TEXT,
            checked_at       TEXT,
            PRIMARY KEY (domain, docroot, whm_source)
        )
    ");

    return $pdo;
}

function clear_existing_source_data(PDO $pdo, string $source): void {
    $pdo->prepare("DELETE FROM wp_installs_woocommerce WHERE whm_source = :source")
        ->execute([':source' => $source]);
}

// ---------------------------------------------------------------------
// WHM / UAPI HELPERS (same pattern as check_wordpress.php)
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
// WOOCOMMERCE DETECTION
// ---------------------------------------------------------------------
/**
 * Checks whether {docroot}/wp-content/plugins/woocommerce exists, and if
 * so reads the version out of woocommerce.php's plugin header comment
 * (the standard "Version: X.Y.Z" line every WP plugin file has).
 */
function check_woocommerce_install(string $username, string $docroot): array {
    $pluginsDir = rtrim($docroot, '/') . '/wp-content/plugins';

    $result = uapi_call($username, 'Fileman', 'list_files', [
        'dir'   => $pluginsDir,
        'types' => 'dir',
    ]);
    $entries = $result['data'] ?? [];
    if (!is_array($entries)) {
        $entries = [];
    }

    $hasWoo = false;
    foreach ($entries as $e) {
        if (($e['type'] ?? '') === 'dir' && strtolower($e['file'] ?? '') === 'woocommerce') {
            $hasWoo = true;
            break;
        }
    }

    if (!$hasWoo) {
        return ['has_woocommerce' => false, 'version' => null];
    }

    $version = null;
    try {
        $content = uapi_call($username, 'Fileman', 'get_file_content', [
            'dir'  => $pluginsDir . '/woocommerce',
            'file' => 'woocommerce.php',
        ]);
        $fileContent = $content['data']['content'] ?? '';
        if (preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $fileContent, $m)) {
            $version = trim($m[1]);
        }
    } catch (Throwable $e) {
        // Non-fatal - folder exists but we couldn't read the header (rare
        // permissions edge case, or a WooCommerce build that strips it).
    }

    return ['has_woocommerce' => true, 'version' => $version];
}

// ---------------------------------------------------------------------
// RUN
// ---------------------------------------------------------------------
function run(): void {
    $pdo = get_db();

    $stmt = $pdo->prepare("
        SELECT domain, account, docroot
        FROM wp_installs
        WHERE whm_source = :source AND has_wordpress = 1
    ");
    $stmt->execute([':source' => WHM_SOURCE_LABEL]);
    $installs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($installs) === 0) {
        fwrite(STDERR, "No confirmed WordPress installs found for source '" . WHM_SOURCE_LABEL . "' in wp_installs - run check_wordpress.php for this source first.\n");
        exit(1);
    }

    echo "Checking " . count($installs) . " confirmed WordPress install(s) for WooCommerce (source: " . WHM_SOURCE_LABEL . ")...\n";

    clear_existing_source_data($pdo, WHM_SOURCE_LABEL);

    $insert = $pdo->prepare("
        INSERT INTO wp_installs_woocommerce
            (domain, whm_source, account, docroot, has_woocommerce, wc_version, check_error, checked_at)
        VALUES
            (:domain, :whm_source, :account, :docroot, :has_woocommerce, :wc_version, :check_error, :checked_at)
    ");

    $syncedAt = date('c');
    $totalWoo = 0;
    $i = 0;

    foreach ($installs as $install) {
        $i++;
        $username = $install['account'];
        $docroot = $install['docroot'];

        try {
            $result = check_woocommerce_install($username, $docroot);
            $insert->execute([
                ':domain'          => $install['domain'],
                ':whm_source'      => WHM_SOURCE_LABEL,
                ':account'         => $username,
                ':docroot'         => $docroot,
                ':has_woocommerce' => $result['has_woocommerce'] ? 1 : 0,
                ':wc_version'      => $result['version'],
                ':check_error'     => null,
                ':checked_at'      => $syncedAt,
            ]);

            if ($result['has_woocommerce']) {
                $totalWoo++;
                $verText = $result['version'] ? "v{$result['version']}" : 'version unknown';
                echo "  [{$i}/" . count($installs) . "] {$install['domain']}: WooCommerce found ({$verText})\n";
            }
        } catch (Throwable $e) {
            $insert->execute([
                ':domain'          => $install['domain'],
                ':whm_source'      => WHM_SOURCE_LABEL,
                ':account'         => $username,
                ':docroot'         => $docroot,
                ':has_woocommerce' => 0,
                ':wc_version'      => null,
                ':check_error'     => $e->getMessage(),
                ':checked_at'      => $syncedAt,
            ]);
            fwrite(STDERR, "  [{$i}/" . count($installs) . "] {$install['domain']}: check failed - " . $e->getMessage() . "\n");
        }

        if ($i % 25 === 0) {
            echo "  ...processed {$i}/" . count($installs) . " installs\n";
        }

        usleep(150000); // 0.15s - be polite to the API
    }

    // CSV of just the WooCommerce sites, version front and center.
    $fh = fopen(CSV_PATH, 'w');
    fputcsv($fh, ['domain', 'account', 'wc_version', 'docroot'], ',', '"', '\\');
    $rows = $pdo->prepare("
        SELECT domain, account, wc_version, docroot
        FROM wp_installs_woocommerce
        WHERE whm_source = :source AND has_woocommerce = 1
        ORDER BY domain
    ");
    $rows->execute([':source' => WHM_SOURCE_LABEL]);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($fh, [$r['domain'], $r['account'], $r['wc_version'], $r['docroot']], ',', '"', '\\');
    }
    fclose($fh);

    echo "\n=== WooCommerce Check Summary (source: " . WHM_SOURCE_LABEL . ") ===\n";
    echo "WordPress installs checked: " . count($installs) . "\n";
    echo "Sites with WooCommerce: {$totalWoo}\n";
    echo "\nWooCommerce site list (with versions) written to " . CSV_PATH . "\n";
    echo "Full results also in websites.sqlite -> wp_installs_woocommerce\n";
}

try {
    run();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
