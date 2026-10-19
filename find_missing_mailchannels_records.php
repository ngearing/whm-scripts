<?php

/**
 * find_missing_mailchannels_records.php
 *
 * Scans every domain in your synced fleet DB (websites.sqlite, built by
 * sync_whm_accounts.php) and checks whether each one already has a
 * MailChannels domain-lockdown TXT record:
 *
 *   _mailchannels.{domain}.  TXT  "v=mc1 auth=xxx"
 *
 * Reports which domains are missing it, which already have it, and which
 * couldn't be checked (no DNS zone found, API error, etc).
 *
 * If any are missing, writes a CSV in the exact format
 * manage_dns_zone_records.php's `bulk-add-csv` command expects
 * (zone,name,type,value,ttl), so the fix is a direct follow-up command:
 *
 *   php manage_dns_zone_records.php bulk-add-csv --file=missing_mailchannels.csv --dry-run
 *   php manage_dns_zone_records.php bulk-add-csv --file=missing_mailchannels.csv
 *
 * -----------------------------------------------------------------------
 * USAGE
 * -----------------------------------------------------------------------
 *
 *   php find_missing_mailchannels_records.php --auth=YOUR_MAILCHANNELS_KEY \
 *       [--domain-type=main|addon] [--out=missing_mailchannels.csv]
 *
 * If your MailChannels key is actually unique PER DOMAIN rather than one
 * shared key, use --auth-map=file.csv instead of --auth, where the CSV has
 * columns: domain,auth
 *
 * Env vars (same as the other scripts): WHM_HOST, WHM_PORT, WHM_USERNAME,
 * WHM_API_TOKEN
 */

// ---------------------------------------------------------------------
// CONFIG - same pattern as sync_whm_accounts.php / manage_dns_zone_records.php
// ---------------------------------------------------------------------

require __DIR__ . '/bootstrap.php';
define_whm_config();

// ---------------------------------------------------------------------
// WHM API HELPER (unchanged)
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
        CURLOPT_HTTPHEADER     => [
            'Authorization: whm ' . WHM_USERNAME . ':' . WHM_API_TOKEN,
        ],
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

/**
 * parse_dns_zone returns metadata.result = 0 both for genuine API errors
 * AND (on some WHM versions) for "zone doesn't exist on this server" -
 * the reason string is the only way to tell them apart, so callers get
 * the raw reason back rather than a boolean.
 */
function fetch_zone_records(string $zone): array {
    $response = whm_api_call('parse_dns_zone', ['zone' => $zone]);

    $result = $response['metadata']['result'] ?? null;
    if ($result !== null && (int)$result !== 1) {
        $reason = $response['metadata']['reason'] ?? 'Unknown error';
        throw new RuntimeException($reason);
    }

    return $response['data']['record'] ?? [];
}

/**
 * True if the zone already has a TXT record at _mailchannels.{domain}.
 * containing "v=mc1" (doesn't require an exact auth-value match, since
 * the goal here is "is lockdown configured at all" - a differing auth
 * value would still show up for manual review via --out).
 */
function has_mailchannels_record(array $records, string $domain): bool {
    $expectedName = rtrim('_mailchannels.' . $domain, '.') . '.';

    foreach ($records as $r) {
        $name = rtrim((string)($r['name'] ?? ''), '.') . '.';
        $type = strtoupper((string)($r['type'] ?? ''));
        $data = $r['txtdata'] ?? $r['record'] ?? '';

        if ($name === $expectedName && $type === 'TXT' && stripos((string)$data, 'v=mc1') !== false) {
            return true;
        }
    }

    return false;
}

// ---------------------------------------------------------------------
// FLEET DB
// ---------------------------------------------------------------------
function get_zones_from_fleet_db(?string $domainType = null): array {
    if (!file_exists(DB_PATH)) {
        throw new RuntimeException("Fleet DB not found at " . DB_PATH . " - run sync_whm_accounts.php first.");
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "SELECT DISTINCT domain FROM whm_domains";
    $params = [];
    if ($domainType !== null) {
        $sql .= " WHERE domain_type = :domain_type";
        $params[':domain_type'] = $domainType;
    }
    $sql .= " ORDER BY domain";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'domain');
}

// ---------------------------------------------------------------------
// AUTH VALUE RESOLUTION
// ---------------------------------------------------------------------
function load_auth_map(string $path): array {
    if (!file_exists($path)) {
        throw new RuntimeException("Auth map file not found: {$path}");
    }
    $map = [];
    if (($handle = fopen($path, 'r')) !== false) {
        $header = array_map('strtolower', array_map('trim', fgetcsv($handle, 0, ',', '"', '\\')));
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $row = array_combine($header, $line);
            if (!empty($row['domain'])) {
                $map[$row['domain']] = $row['auth'] ?? '';
            }
        }
        fclose($handle);
    }
    return $map;
}

// ---------------------------------------------------------------------
// CLI ARG PARSING
// ---------------------------------------------------------------------
function parse_cli_args(array $argv): array {
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-zA-Z0-9_-]+)=(.*)$/s', $arg, $m)) {
            $args[$m[1]] = $m[2];
        } elseif (preg_match('/^--([a-zA-Z0-9_-]+)$/', $arg, $m)) {
            $args[$m[1]] = true;
        }
    }
    return $args;
}

// ---------------------------------------------------------------------
// RUN
// ---------------------------------------------------------------------
try {
    $args = parse_cli_args($argv);

    if (!isset($args['auth']) && !isset($args['auth-map'])) {
        fwrite(STDERR, "Error: pass --auth=YOUR_KEY (shared value) or --auth-map=file.csv (per-domain values).\n");
        exit(1);
    }

    $authMap = null;
    if (isset($args['auth-map'])) {
        $authMap = load_auth_map($args['auth-map']);
    }

    $domainType = $args['domain-type'] ?? null; // null = all types (main + addon + parked)
    $outPath = $args['out'] ?? output_path('missing_mailchannels.csv');

    $zones = get_zones_from_fleet_db($domainType);
    $total = count($zones);
    echo "Checking {$total} domain(s) for an existing _mailchannels TXT record"
        . ($domainType ? " (domain-type={$domainType})" : "") . "...\n";

    $missing = [];
    $found = [];
    $errored = [];

    foreach ($zones as $i => $domain) {
        $n = $i + 1;

        if ($authMap !== null && !isset($authMap[$domain])) {
            fwrite(STDERR, "  [{$n}/{$total}] SKIP {$domain}: no auth value in --auth-map\n");
            $errored[] = [$domain, 'no auth value in map'];
            continue;
        }

        try {
            $records = fetch_zone_records($domain);
            if (has_mailchannels_record($records, $domain)) {
                echo "  [{$n}/{$total}] OK      {$domain} (already has record)\n";
                $found[] = $domain;
            } else {
                echo "  [{$n}/{$total}] MISSING {$domain}\n";
                $missing[] = $domain;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "  [{$n}/{$total}] ERROR   {$domain}: " . $e->getMessage() . "\n");
            $errored[] = [$domain, $e->getMessage()];
        }

        usleep(150000); // same politeness pause as the other fleet scripts
    }

    echo "\nSummary: " . count($found) . " already configured, "
        . count($missing) . " missing, " . count($errored) . " could not be checked.\n";

    if (!empty($missing)) {
        $fh = fopen($outPath, 'w');
        fputcsv($fh, ['zone', 'name', 'type', 'value', 'ttl'], ',', '"', '\\');
        foreach ($missing as $domain) {
            $auth = $authMap !== null ? $authMap[$domain] : $args['auth'];
            $name = '_mailchannels.' . $domain . '.';
            $value = 'v=mc1 auth=' . $auth;
            fputcsv($fh, [$domain, $name, 'TXT', $value, 14400], ',', '"', '\\');
        }
        fclose($fh);

        echo "\nWrote {$outPath} - ready for:\n";
        echo "  php manage_dns_zone_records.php bulk-add-csv --file={$outPath} --dry-run\n";
        echo "  php manage_dns_zone_records.php bulk-add-csv --file={$outPath}\n";
    }

    if (!empty($errored)) {
        echo "\nDomains that could not be checked (review manually):\n";
        foreach ($errored as [$domain, $reason]) {
            echo "  - {$domain}: {$reason}\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
