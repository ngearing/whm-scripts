<?php

/**
 * sync_synergy_domains.php
 *
 * Pulls the full domain list from the Synergy Wholesale SOAP API (listDomains
 * command), paginating through all pages (max 500 per page per their docs),
 * and stores the results into a local SQLite database.
 *
 * This is step 1 of the reconciliation pipeline: "Pull domains from Synergy
 * and match up parked/add-ons". Run sync_whm_accounts.php separately to pull
 * the Shock/WHM side, then a third reconcile script diffs the two tables.
 *
 * Usage:
 *   php sync_synergy_domains.php
 *
 * Requires:
 *   - PHP SOAP extension enabled (php-soap)
 *   - PHP PDO SQLite extension enabled (php-sqlite3)
 *   - Your connecting server's IP whitelisted in Synergy Wholesale ->
 *     Account Functions -> API Information
 */

// ---------------------------------------------------------------------
// CONFIG - fill these in, or better, load from environment variables so
// credentials never end up committed to source control.
// ---------------------------------------------------------------------
define('SYNERGY_RESELLER_ID', getenv('SYNERGY_RESELLER_ID') ?: 'YOUR_RESELLER_ID');
define('SYNERGY_API_KEY',     getenv('SYNERGY_API_KEY')     ?: 'YOUR_API_KEY');

// Synergy's API location per their docs. Their SOAP service exposes a WSDL
// at this endpoint - if this throws a "could not connect to host" or WSDL
// parse error, double check the exact WSDL URL in the API documentation PDF
// (Account Functions -> API Information -> API & WHMCS Modules), as
// providers occasionally version this path.
define('SYNERGY_WSDL', 'https://api.synergywholesale.com/?wsdl');

// Where to store the synced data. SQLite keeps this dependency-free; swap
// for a MySQL PDO DSN later if you want this shared across a team.
define('DB_PATH', __DIR__ . '/websites.sqlite');

// Synergy caps pages at 500 domains - use the max to minimize round trips
// for a 400+ domain account (this likely means everything fits on page 1,
// but the loop below handles accounts that grow past 500 without changes).
define('PAGE_LIMIT', 500);

// ---------------------------------------------------------------------
// DB SETUP
// ---------------------------------------------------------------------
function get_db(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS synergy_domains (
            domain_name       TEXT PRIMARY KEY,
            domain_status     TEXT,
            domain_expiry     TEXT,
            name_servers      TEXT,   -- JSON-encoded array
            dns_config_name   TEXT,
            auto_renew        TEXT,
            id_protect        TEXT,
            raw_status        TEXT,   -- the 'status' field Synergy returned for this row
            error_message     TEXT,   -- populated if this domain returned an error
            last_synced_at    TEXT
        )
    ");

    return $pdo;
}

// ---------------------------------------------------------------------
// SYNC LOGIC
// ---------------------------------------------------------------------
function sync_synergy_domains(): void {
    echo "Connecting to Synergy Wholesale API...\n";

    $client = new SoapClient(SYNERGY_WSDL, [
        'trace'      => true,   // keep true while debugging; lets you inspect __getLastRequest()
        'exceptions' => true,
        'cache_wsdl' => WSDL_CACHE_NONE,
        'connection_timeout' => 30,
    ]);

    $pdo = get_db();
    $upsert = $pdo->prepare("
        INSERT INTO synergy_domains
            (domain_name, domain_status, domain_expiry, name_servers,
             dns_config_name, auto_renew, id_protect, raw_status,
             error_message, last_synced_at)
        VALUES
            (:domain_name, :domain_status, :domain_expiry, :name_servers,
             :dns_config_name, :auto_renew, :id_protect, :raw_status,
             :error_message, :last_synced_at)
        ON CONFLICT(domain_name) DO UPDATE SET
            domain_status    = excluded.domain_status,
            domain_expiry    = excluded.domain_expiry,
            name_servers     = excluded.name_servers,
            dns_config_name  = excluded.dns_config_name,
            auto_renew       = excluded.auto_renew,
            id_protect       = excluded.id_protect,
            raw_status       = excluded.raw_status,
            error_message    = excluded.error_message,
            last_synced_at   = excluded.last_synced_at
    ");

    $page = 1;
    $totalSynced = 0;
    $syncedAt = date('c');

    while (true) {
        echo "Fetching page {$page}...\n";

        $response = $client->listDomains([
            'resellerID' => SYNERGY_RESELLER_ID,
            'apiKey'     => SYNERGY_API_KEY,
            'page'       => $page,
            'limit'      => PAGE_LIMIT,
        ]);

        if (!isset($response->status) || $response->status !== 'OK') {
            $msg = $response->errorMessage ?? 'Unknown error';
            fwrite(STDERR, "Synergy API error on page {$page}: {$msg}\n");
            break;
        }

        // domainList may come back as a single object (not an array) if
        // there's only one result - normalize to an array either way.
        $domains = $response->domainList ?? [];
        if (!is_array($domains)) {
            $domains = [$domains];
        }

        if (count($domains) === 0) {
            echo "No more domains returned - sync complete.\n";
            break;
        }

        $pdo->beginTransaction();
        foreach ($domains as $domain) {
            $nameServers = isset($domain->nameServers)
                ? json_encode((array) $domain->nameServers)
                : null;

            $upsert->execute([
                ':domain_name'     => $domain->domainName ?? null,
                ':domain_status'   => $domain->domainStatus ?? ($domain->domain_status ?? null),
                ':domain_expiry'   => $domain->domain_expiry ?? null,
                ':name_servers'    => $nameServers,
                ':dns_config_name' => $domain->dnsConfigName ?? null,
                ':auto_renew'      => $domain->autoRenew ?? null,
                ':id_protect'      => $domain->idProtect ?? null,
                ':raw_status'      => $domain->status ?? null,
                ':error_message'   => $domain->errorMessage ?? null,
                ':last_synced_at'  => $syncedAt,
            ]);
            $totalSynced++;
        }
        $pdo->commit();

        echo "  -> stored " . count($domains) . " domains from page {$page}\n";

        // If this page returned fewer than the limit, we've hit the last page.
        if (count($domains) < PAGE_LIMIT) {
            break;
        }

        $page++;

        // Small delay to be a polite API citizen on large syncs.
        usleep(250000); // 0.25s
    }

    echo "\nDone. Synced {$totalSynced} domain records into " . DB_PATH . "\n";
    echo "Rows with domain_expiry before " . date('Y-m-d', strtotime('+30 days')) . " are expiring within 30 days.\n";
}

// ---------------------------------------------------------------------
// RUN
// ---------------------------------------------------------------------
try {
    sync_synergy_domains();
} catch (SoapFault $e) {
    fwrite(STDERR, "SOAP fault: " . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
