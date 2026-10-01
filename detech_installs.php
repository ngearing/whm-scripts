<?php

/**
 * detect_new_installs.php
 *
 * First piece of the "auto-install MainWP child on new installs" objective.
 * This script does ONLY detection - it does not install anything or touch
 * MainWP. It compares the current state (from sync_whm_accounts.php and
 * check_wordpress_wptoolkit.php / check_wordpress.php) against a
 * persistent tracking table, and flags:
 *
 *   - NEW cPanel accounts that weren't seen on a previous run
 *   - NEW WordPress installs (by domain+docroot) that weren't seen before
 *
 * Once the actual install mechanism exists (pending: discovering WP
 * Toolkit's plugin-install API), a follow-up script will read the rows
 * this one flags as new-and-not-yet-handled (notified_at IS NULL), install
 * MainWP Child on each, register them via MainWP's REST API, and then
 * stamp notified_at so they're not re-processed on the next run.
 *
 * IMPORTANT: on the very first run, EVERYTHING will show as "new" since
 * the tracking table starts empty - that's expected, not a bug. Treat the
 * first run as establishing a baseline, not a list of things to act on.
 *
 * Usage:
 *   WHM_SOURCE_LABEL=shock-1 php detect_new_installs.php
 *
 * Run this after sync_whm_accounts.php and check_wordpress_wptoolkit.php
 * (or check_wordpress.php) have completed for the source. Doesn't need
 * WHM credentials itself - it only reads from the local database.
 */

define('WHM_SOURCE_LABEL', getenv('WHM_SOURCE_LABEL') ?: 'YOUR_SOURCE_LABEL');
define('DB_PATH', __DIR__ . '/websites.sqlite');

function get_db(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS install_tracking_accounts (
            username       TEXT,
            whm_source     TEXT,
            first_seen_at  TEXT,
            last_seen_at   TEXT,
            notified_at    TEXT,
            PRIMARY KEY (username, whm_source)
        )
    ");

    // Tracks WordPress installs by domain+docroot (not just domain), so a
    // second install added later in a subfolder of an already-known
    // domain is correctly flagged as new too.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS install_tracking_wp (
            domain         TEXT,
            docroot        TEXT,
            whm_source     TEXT,
            account        TEXT,
            first_seen_at  TEXT,
            last_seen_at   TEXT,
            notified_at    TEXT,
            PRIMARY KEY (domain, docroot, whm_source)
        )
    ");

    return $pdo;
}

/**
 * Diffs current rows against the tracking table for one entity type.
 * Returns the list of genuinely new keys found this run.
 *
 * @param array $currentRows      Rows from the live data (e.g. whm_accounts)
 * @param callable $keyFn         Extracts a unique key array from a row (for binding)
 * @param string $trackingTable
 * @param array $insertColumns    Extra columns (beyond first_seen_at/last_seen_at) to populate on first insert
 */
function diff_and_track(PDO $pdo, array $currentRows, callable $keyFn, string $trackingTable, array $keyColumns, array $extraColumns = []): array {
    $now = date('c');
    $newItems = [];

    $checkStmt = $pdo->prepare(
        "
        SELECT 1 FROM {$trackingTable}
        WHERE " . implode(' AND ', array_map(fn($c) => "{$c} = :{$c}", $keyColumns))
    );

    $insertStmt = $pdo->prepare("
        INSERT INTO {$trackingTable}
            (" . implode(', ', array_merge($keyColumns, array_keys($extraColumns), ['first_seen_at', 'last_seen_at'])) . ")
        VALUES
            (" . implode(', ', array_map(fn($c) => ":{$c}", array_merge($keyColumns, array_keys($extraColumns)))) . ", :first_seen_at, :last_seen_at)
    ");

    $updateStmt = $pdo->prepare(
        "
        UPDATE {$trackingTable} SET last_seen_at = :last_seen_at
        WHERE " . implode(' AND ', array_map(fn($c) => "{$c} = :{$c}", $keyColumns))
    );

    foreach ($currentRows as $row) {
        $keyValues = $keyFn($row);

        $checkStmt->execute($keyValues);
        $exists = $checkStmt->fetch();

        if ($exists) {
            $updateStmt->execute(array_merge($keyValues, [':last_seen_at' => $now]));
        } else {
            $extraValues = [];
            foreach ($extraColumns as $col => $getter) {
                $extraValues[":{$col}"] = $getter($row);
            }
            $insertStmt->execute(array_merge($keyValues, $extraValues, [
                ':first_seen_at' => $now,
                ':last_seen_at'  => $now,
            ]));
            $newItems[] = $row;
        }
    }

    return $newItems;
}

function run(): void {
    $pdo = get_db();
    $isFirstRun = false;

    $accountCount = (int) $pdo->query("SELECT COUNT(*) FROM install_tracking_accounts")->fetchColumn();
    $wpCount = (int) $pdo->query("SELECT COUNT(*) FROM install_tracking_wp")->fetchColumn();
    if ($accountCount === 0 && $wpCount === 0) {
        $isFirstRun = true;
    }

    // --- New cPanel accounts ---
    $accountsStmt = $pdo->prepare("SELECT username FROM whm_accounts WHERE whm_source = :source");
    $accountsStmt->execute([':source' => WHM_SOURCE_LABEL]);
    $accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

    $newAccounts = diff_and_track(
        $pdo,
        $accounts,
        fn($row) => [':username' => $row['username'], ':whm_source' => WHM_SOURCE_LABEL],
        'install_tracking_accounts',
        ['username', 'whm_source']
    );

    // --- New WordPress installs ---
    // Pull from wp_installs_toolkit if it has rows for this source, since
    // it's the richer data source; fall back to wp_installs (the plain
    // file-scan table) if the WP Toolkit script hasn't been run.
    $wptCount = $pdo->prepare("SELECT COUNT(*) FROM wp_installs_toolkit WHERE whm_source = :source");
    $wptCount->execute([':source' => WHM_SOURCE_LABEL]);
    $useToolkitTable = ((int) $wptCount->fetchColumn()) > 0;

    if ($useToolkitTable) {
        $wpStmt = $pdo->prepare("
            SELECT domain_name as domain, path as docroot, account
            FROM wp_installs_toolkit
            WHERE whm_source = :source AND domain_name IS NOT NULL AND path IS NOT NULL
        ");
    } else {
        $wpStmt = $pdo->prepare("
            SELECT domain, docroot, account
            FROM wp_installs
            WHERE whm_source = :source AND has_wordpress = 1
        ");
    }
    $wpStmt->execute([':source' => WHM_SOURCE_LABEL]);
    $wpInstalls = $wpStmt->fetchAll(PDO::FETCH_ASSOC);

    $newWpInstalls = diff_and_track(
        $pdo,
        $wpInstalls,
        fn($row) => [':domain' => $row['domain'], ':docroot' => $row['docroot'], ':whm_source' => WHM_SOURCE_LABEL],
        'install_tracking_wp',
        ['domain', 'docroot', 'whm_source'],
        ['account' => fn($row) => $row['account']]
    );

    // --- Report ---
    if ($isFirstRun) {
        echo "=== First run for source '" . WHM_SOURCE_LABEL . "' - establishing baseline ===\n";
        echo "Tracked " . count($accounts) . " existing accounts and " . count($wpInstalls) . " existing WP installs.\n";
        echo "Nothing to act on yet - future runs will only flag genuinely NEW items from here.\n";
        return;
    }

    echo "=== New Since Last Run (source: " . WHM_SOURCE_LABEL . ") ===\n";
    echo "New cPanel accounts: " . count($newAccounts) . "\n";
    foreach ($newAccounts as $a) {
        echo "  + {$a['username']}\n";
    }

    echo "\nNew WordPress installs: " . count($newWpInstalls) . "\n";
    foreach ($newWpInstalls as $w) {
        echo "  + {$w['domain']} ({$w['account']}) - {$w['docroot']}\n";
    }

    if (count($newAccounts) === 0 && count($newWpInstalls) === 0) {
        echo "\nNothing new since last run.\n";
    } else {
        echo "\nThese are queued for MainWP Child install once that step is built (tracked via notified_at IS NULL in install_tracking_wp / install_tracking_accounts).\n";
    }
}

try {
    run();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
