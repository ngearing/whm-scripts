<?php

/**
 * manage_dns_zone_records.php
 *
 * Adds, edits, removes, and lists DNS zone records via the WHM API,
 * built on the same config/whm_api_call() pattern as sync_whm_accounts.php
 * (same env vars, same cURL helper, same reseller-friendly auth style).
 *
 * Requires the `manage-dns-records` ACL on the WHM API token (WHM ->
 * Development -> Manage API Tokens). Unlike the account/PHP-version sync,
 * DNS zone editing IS available directly at the whmapi1 level on Shock's
 * reseller ACLs, so this does NOT need the uapi_cpanel proxy trick.
 *
 * WHM API functions used:
 *   - parse_dns_zone     : read a zone's current records (with line numbers)
 *   - add_zone_record    : append a new record to an existing zone
 *   - edit_zone_record   : modify a record by its line number
 *   - remove_zone_record : delete a record by its line number
 *
 * -----------------------------------------------------------------------
 * USAGE
 * -----------------------------------------------------------------------
 *
 * List a zone's records (to find line numbers for edit/remove):
 *   php manage_dns_zone_records.php list --zone=example.com
 *
 * Add a record to a single zone:
 *   php manage_dns_zone_records.php add --zone=example.com \
 *       --name=sub.example.com. --type=A --value=192.0.2.10 [--ttl=14400]
 *
 *   php manage_dns_zone_records.php add --zone=example.com \
 *       --name=example.com. --type=TXT --value="v=spf1 include:_spf.example.com ~all"
 *
 *   php manage_dns_zone_records.php add --zone=example.com \
 *       --name=mail.example.com. --type=CNAME --value=example.com.
 *
 *   php manage_dns_zone_records.php add --zone=example.com \
 *       --name=example.com. --type=MX --value=mail.example.com. --priority=10
 *
 * Edit a record (line number comes from `list`):
 *   php manage_dns_zone_records.php edit --zone=example.com --line=14 \
 *       --type=A --value=192.0.2.20
 *
 * Remove a record:
 *   php manage_dns_zone_records.php remove --zone=example.com --line=14
 *
 * Bulk-add the SAME record across every zone/domain in your synced fleet
 * (reads whm_domains from websites.sqlite, the DB sync_whm_accounts.php
 * already builds), e.g. rolling out a new TXT or CAA record everywhere:
 *   php manage_dns_zone_records.php bulk-add \
 *       --name=@ --type=CAA --value='0 issue "letsencrypt.org"' \
 *       [--domain-type=main] [--dry-run]
 *
 * Bulk-add from an explicit CSV instead (zone,name,type,value[,ttl,priority]):
 *   php manage_dns_zone_records.php bulk-add-csv --file=records.csv [--dry-run]
 *
 * Env vars (same as sync_whm_accounts.php):
 *   WHM_HOST, WHM_PORT, WHM_USERNAME, WHM_API_TOKEN
 */

// ---------------------------------------------------------------------
// CONFIG - identical pattern to sync_whm_accounts.php
// ---------------------------------------------------------------------

require __DIR__ . '/bootstrap.php';
define_whm_config();

// DB_PATH (from bootstrap.php) is the same DB the sync scripts use, so
// bulk-add can pull the domain fleet from whm_domains without you
// having to hand-type 200+ zone names.

// ---------------------------------------------------------------------
// WHM API HELPER (unchanged from sync_whm_accounts.php)
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
 * whmapi1 wraps success/failure differently across functions - some use
 * metadata.result, DNS functions typically use metadata.result too, but
 * add/edit/remove also return a per-record "status" in data. This checks
 * both layers and throws with the most specific reason available.
 */
function assert_whm_success(array $response, string $context): void {
    $result = $response['metadata']['result'] ?? null;
    if ($result !== null && (int)$result !== 1) {
        $reason = $response['metadata']['reason'] ?? 'Unknown error';
        throw new RuntimeException("{$context} failed: {$reason}");
    }
}

// ---------------------------------------------------------------------
// DNS ZONE OPERATIONS
// ---------------------------------------------------------------------

/**
 * Returns the zone's current records as a flat array, each with the line
 * number needed for edit_zone_record/remove_zone_record.
 */
function list_dns_zone_records(string $zone): array {
    $response = whm_api_call('parse_dns_zone', ['zone' => $zone]);
    assert_whm_success($response, "parse_dns_zone({$zone})");

    return $response['data']['record'] ?? [];
}

/**
 * Adds one record to an existing zone.
 *
 * @param string $zone     Zone/domain the record belongs to, e.g. "example.com"
 * @param string $name     Record name, e.g. "sub.example.com." (trailing dot expected)
 * @param string $type     A, AAAA, CNAME, TXT, MX, CAA, NS, SRV, etc.
 * @param string $value    Record data - meaning depends on $type:
 *                           A/AAAA  -> IP address
 *                           CNAME/NS -> target hostname (with trailing dot)
 *                           TXT     -> text data
 *                           MX      -> mail exchanger hostname (use $priority for preference)
 *                           CAA     -> full CAA value string, e.g. '0 issue "letsencrypt.org"'
 * @param int    $ttl
 * @param int|null $priority Required for MX/SRV records
 */
function add_dns_zone_record(
    string $zone,
    string $name,
    string $type,
    string $value,
    int $ttl = 14400,
    ?int $priority = null
): array {
    $params = [
        'zone' => $zone,
        'name' => $name,
        'type' => strtoupper($type),
        'ttl'  => $ttl,
    ];

    switch (strtoupper($type)) {
        case 'A':
        case 'AAAA':
            $params['address'] = $value;
            break;
        case 'CNAME':
        case 'NS':
        case 'PTR':
            $params['cname'] = $value;
            break;
        case 'TXT':
        case 'SPF':
            $params['txtdata'] = $value;
            break;
        case 'MX':
            $params['exchange'] = $value;
            $params['preference'] = $priority ?? 10;
            break;
        case 'CAA':
            $params['caavalue'] = $value; // full "flag tag value" string
            break;
        case 'SRV':
            $params['target'] = $value;
            $params['priority'] = $priority ?? 10;
            break;
        default:
            // Fallback for less common types - most of the API accepts
            // a generic 'record' free-text field.
            $params['record'] = $value;
            break;
    }

    $response = whm_api_call('add_zone_record', $params);
    assert_whm_success($response, "add_zone_record({$zone} {$name} {$type})");

    return $response;
}

function edit_dns_zone_record(
    string $zone,
    int $line,
    string $type,
    string $value,
    ?int $ttl = null,
    ?int $priority = null
): array {
    $params = [
        'zone' => $zone,
        'line' => $line,
        'type' => strtoupper($type),
    ];
    if ($ttl !== null) {
        $params['ttl'] = $ttl;
    }

    switch (strtoupper($type)) {
        case 'A':
        case 'AAAA':
            $params['address'] = $value;
            break;
        case 'CNAME':
        case 'NS':
        case 'PTR':
            $params['cname'] = $value;
            break;
        case 'TXT':
        case 'SPF':
            $params['txtdata'] = $value;
            break;
        case 'MX':
            $params['exchange'] = $value;
            if ($priority !== null) {
                $params['preference'] = $priority;
            }
            break;
        case 'CAA':
            $params['caavalue'] = $value;
            break;
        case 'SRV':
            $params['target'] = $value;
            if ($priority !== null) {
                $params['priority'] = $priority;
            }
            break;
        default:
            $params['record'] = $value;
            break;
    }

    $response = whm_api_call('edit_zone_record', $params);
    assert_whm_success($response, "edit_zone_record({$zone} line {$line})");

    return $response;
}

function remove_dns_zone_record(string $zone, int $line): array {
    $response = whm_api_call('remove_zone_record', [
        'zone' => $zone,
        'line' => $line,
    ]);
    assert_whm_success($response, "remove_zone_record({$zone} line {$line})");

    return $response;
}

// ---------------------------------------------------------------------
// BULK MODE - pulls zones from the shared websites.sqlite fleet DB
// ---------------------------------------------------------------------

/**
 * Reads distinct zones (domains) from whm_domains, optionally filtered
 * by domain_type ('main', 'addon', 'parked'). This is the same table
 * sync_whm_accounts.php populates, so a bulk-add always targets whatever
 * the fleet looked like as of the last sync.
 */
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

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'domain');
}

function bulk_add_record_to_zones(
    array $zones,
    string $name,
    string $type,
    string $value,
    int $ttl,
    ?int $priority,
    bool $dryRun
): void {
    $total = count($zones);
    $ok = 0;
    $failed = 0;

    echo "Adding {$type} record '{$name}' -> '{$value}' across {$total} zone(s)"
        . ($dryRun ? " [DRY RUN - no changes will be made]" : "") . "\n";

    foreach ($zones as $i => $zone) {
        $n = $i + 1;
        // Resolve a bare "@" name against each zone individually.
        $recordName = ($name === '@') ? ($zone . '.') : $name;

        if ($dryRun) {
            echo "  [{$n}/{$total}] would add to {$zone}: {$recordName} {$type} {$value}\n";
            continue;
        }

        try {
            add_dns_zone_record($zone, $recordName, $type, $value, $ttl, $priority);
            echo "  [{$n}/{$total}] OK   {$zone}\n";
            $ok++;
        } catch (Throwable $e) {
            fwrite(STDERR, "  [{$n}/{$total}] FAIL {$zone}: " . $e->getMessage() . "\n");
            $failed++;
        }

        // Same politeness pause as sync_account_details() to avoid
        // tripping rate limiting across hundreds of sequential calls.
        usleep(150000); // 0.15s
    }

    if (!$dryRun) {
        echo "Done. {$ok} succeeded, {$failed} failed out of {$total}.\n";
    }
}

function bulk_add_from_csv(string $path, bool $dryRun): void {
    $path = resolve_input_path($path); // bare names are also looked up in output/
    if (!file_exists($path)) {
        throw new RuntimeException("CSV file not found: {$path}");
    }

    $rows = [];
    if (($handle = fopen($path, 'r')) !== false) {
        $header = fgetcsv($handle, 0, ',', '"', '\\'); // zone,name,type,value[,ttl,priority]
        $header = array_map('strtolower', array_map('trim', $header));

        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $row = array_combine($header, $line);
            $rows[] = $row;
        }
        fclose($handle);
    }

    $total = count($rows);
    $ok = 0;
    $failed = 0;

    echo "Adding " . $total . " record(s) from {$path}"
        . ($dryRun ? " [DRY RUN - no changes will be made]" : "") . "\n";

    foreach ($rows as $i => $row) {
        $n = $i + 1;
        $zone = $row['zone'] ?? null;
        $name = $row['name'] ?? null;
        $type = $row['type'] ?? null;
        $value = $row['value'] ?? null;
        $ttl = isset($row['ttl']) && $row['ttl'] !== '' ? (int)$row['ttl'] : 14400;
        $priority = isset($row['priority']) && $row['priority'] !== '' ? (int)$row['priority'] : null;

        if (!$zone || !$name || !$type || $value === null) {
            fwrite(STDERR, "  [{$n}/{$total}] SKIP - missing required column(s) in row: " . json_encode($row) . "\n");
            $failed++;
            continue;
        }

        if ($dryRun) {
            echo "  [{$n}/{$total}] would add to {$zone}: {$name} {$type} {$value}\n";
            continue;
        }

        try {
            add_dns_zone_record($zone, $name, $type, $value, $ttl, $priority);
            echo "  [{$n}/{$total}] OK   {$zone} {$name} {$type}\n";
            $ok++;
        } catch (Throwable $e) {
            fwrite(STDERR, "  [{$n}/{$total}] FAIL {$zone} {$name} {$type}: " . $e->getMessage() . "\n");
            $failed++;
        }

        usleep(150000);
    }

    if (!$dryRun) {
        echo "Done. {$ok} succeeded, {$failed} failed out of {$total}.\n";
    }
}

// ---------------------------------------------------------------------
// CLI ARG PARSING
// ---------------------------------------------------------------------
function parse_cli_args(array $argv): array {
    $command = $argv[1] ?? null;
    $args = [];
    foreach (array_slice($argv, 2) as $arg) {
        if (preg_match('/^--([a-zA-Z0-9_-]+)=(.*)$/s', $arg, $m)) {
            $args[$m[1]] = $m[2];
        } elseif (preg_match('/^--([a-zA-Z0-9_-]+)$/', $arg, $m)) {
            $args[$m[1]] = true; // boolean flags like --dry-run
        }
    }
    return [$command, $args];
}

function require_args(array $args, array $keys, string $command): void {
    foreach ($keys as $key) {
        if (!isset($args[$key])) {
            fwrite(STDERR, "Error: --{$key} is required for the '{$command}' command.\n");
            exit(1);
        }
    }
}

// ---------------------------------------------------------------------
// RUN
// ---------------------------------------------------------------------
try {
    [$command, $args] = parse_cli_args($argv);

    switch ($command) {
        case 'list':
            require_args($args, ['zone'], 'list');
            $records = list_dns_zone_records($args['zone']);
            foreach ($records as $r) {
                printf(
                    "line %-4s %-30s %-6s ttl=%-6s %s\n",
                    $r['Line'] ?? '?',
                    $r['name'] ?? '',
                    $r['type'] ?? '',
                    $r['ttl'] ?? '',
                    $r['address'] ?? $r['cname'] ?? $r['txtdata'] ?? $r['record'] ?? ''
                );
            }
            break;

        case 'add':
            require_args($args, ['zone', 'name', 'type', 'value'], 'add');
            add_dns_zone_record(
                $args['zone'],
                $args['name'],
                $args['type'],
                $args['value'],
                isset($args['ttl']) ? (int)$args['ttl'] : 14400,
                isset($args['priority']) ? (int)$args['priority'] : null
            );
            echo "Added {$args['type']} record for {$args['name']} in zone {$args['zone']}.\n";
            break;

        case 'edit':
            require_args($args, ['zone', 'line', 'type', 'value'], 'edit');
            edit_dns_zone_record(
                $args['zone'],
                (int)$args['line'],
                $args['type'],
                $args['value'],
                isset($args['ttl']) ? (int)$args['ttl'] : null,
                isset($args['priority']) ? (int)$args['priority'] : null
            );
            echo "Edited line {$args['line']} in zone {$args['zone']}.\n";
            break;

        case 'remove':
            require_args($args, ['zone', 'line'], 'remove');
            remove_dns_zone_record($args['zone'], (int)$args['line']);
            echo "Removed line {$args['line']} from zone {$args['zone']}.\n";
            break;

        case 'bulk-add':
            require_args($args, ['name', 'type', 'value'], 'bulk-add');
            $zones = get_zones_from_fleet_db($args['domain-type'] ?? null);
            bulk_add_record_to_zones(
                $zones,
                $args['name'],
                $args['type'],
                $args['value'],
                isset($args['ttl']) ? (int)$args['ttl'] : 14400,
                isset($args['priority']) ? (int)$args['priority'] : null,
                isset($args['dry-run'])
            );
            break;

        case 'bulk-add-csv':
            require_args($args, ['file'], 'bulk-add-csv');
            bulk_add_from_csv($args['file'], isset($args['dry-run']));
            break;

        default:
            echo "Usage: php manage_dns_zone_records.php <command> [--options]\n\n";
            echo "Commands:\n";
            echo "  list         --zone=DOMAIN\n";
            echo "  add          --zone=DOMAIN --name=NAME --type=TYPE --value=VALUE [--ttl=N] [--priority=N]\n";
            echo "  edit         --zone=DOMAIN --line=N --type=TYPE --value=VALUE [--ttl=N] [--priority=N]\n";
            echo "  remove       --zone=DOMAIN --line=N\n";
            echo "  bulk-add     --name=NAME --type=TYPE --value=VALUE [--domain-type=main|addon|parked] [--ttl=N] [--priority=N] [--dry-run]\n";
            echo "  bulk-add-csv --file=records.csv [--dry-run]\n";
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
