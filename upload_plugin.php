#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * upload_plugin.php
 *
 * Pushes a must-use plugin into wp-content/mu-plugins/ of every WordPress
 * install on every cPanel account reachable from your WHM reseller sources.
 *
 * Flow per source:  WHM listaccts
 *   -> per account:  UAPI DomainInfo::domains_data (all docroots)
 *   -> per docroot:  UAPI Fileman::list_files (is it WordPress? mu-plugins dir?)
 *                    [API2 Fileman::mkdir if mu-plugins is missing]
 *                    UAPI Fileman::save_file_content, then read back + compare
 *
 * Everything goes through WHM's uapi_cpanel proxy using reseller API tokens, so
 * no per-account cPanel passwords or sessions are needed.
 *
 * CONFIG (.env next to this script, or real environment variables):
 *   Standard single-reseller setup (same as the other scripts):
 *     WHM_HOST=server.example.com
 *     WHM_USERNAME=reselleruser
 *     WHM_API_TOKEN=XXXXXXXX
 *     WHM_SOURCE_LABEL=shock-1           (optional)
 *
 *   Optional multi-reseller setup:
 *     WHM_SOURCES=shock1,shock2
 *     WHM_SHOCK1_HOST=server1.example.com
 *     WHM_SHOCK1_USER=reselleruser
 *     WHM_SHOCK1_TOKEN=XXXXXXXX
 *     WHM_SHOCK1_PORT=2087            (optional, default 2087)
 *     WHM_SHOCK1_INSECURE=1           (optional, skip TLS verification)
 *
 * USAGE
 *     php upload_plugin.php --dry-run                  # see what would happen
 *     php upload_plugin.php --user=someacct --verify   # one account, then probe it
 *     php upload_plugin.php --limit=5 --verify         # first 5 accounts
 *     php upload_plugin.php --verify                   # everything
 *     php upload_plugin.php --remove --dry-run         # rollback preview
 *
 * OPTIONS
 *     --dry-run        Read-only. Report what would be installed/updated/removed.
 *     --remove         Delete the mu-plugin instead of installing it.
 *     --verify         After install, request /?author=1 on the site: 404 = working,
 *                      5xx = BROKEN (roll back that site), anything else = not effective.
 *     --source=a,b     Only these WHM sources (names from WHM_SOURCES).
 *     --user=a,b       Only these cPanel usernames.
 *     --limit=N        Stop after N accounts (use for a first small batch).
 *     --file=PATH      Plugin file to deploy (default: plugins/gg-user-protect.php).
 *     --delay=MS       Pause between API calls (default 250).
 *
 * Idempotent: identical files are reported "up-to-date" and not rewritten, so
 * re-running after a failure or after editing the plugin is safe.
 *
 * Report: output/mu-plugin-deploy-YYYYmmdd-HHMMSS.csv
 */

require __DIR__ . '/bootstrap.php';

const DEFAULT_PLUGIN = __DIR__ . '/plugins/gg-user-protect.php';

/* ------------------------------------------------------------------------- */
/* Config                                                                    */
/* ------------------------------------------------------------------------- */

function fail(string $msg, int $code = 1): never {
    fwrite(STDERR, "ERROR: $msg\n");
    exit($code);
}

/**
 * @return array<int, array{name:string,host:string,port:int,user:string,token:string,verify_tls:bool}>
 */
function load_sources(): array {
    $configuredSources = env('WHM_SOURCES');
    if ($configuredSources === null) {
        define_whm_config();
        return [[
            'name'       => WHM_SOURCE_LABEL,
            'host'       => WHM_HOST,
            'port'       => (int) WHM_PORT,
            'user'       => WHM_USERNAME,
            'token'      => WHM_API_TOKEN,
            'verify_tls' => true,
        ]];
    }

    $names = array_values(array_filter(array_map('trim', explode(',', $configuredSources))));
    if (!$names) {
        fail('WHM_SOURCES is empty. Set e.g. WHM_SOURCES=shock1,shock2 (see script header).');
    }

    $sources = [];
    foreach ($names as $name) {
        $p = 'WHM_' . strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '_', $name)) . '_';
        $host  = (string) getenv($p . 'HOST');
        $user  = (string) getenv($p . 'USER');
        $token = (string) getenv($p . 'TOKEN');
        if ($host === '' || $user === '' || $token === '') {
            fail("Source '$name': need {$p}HOST, {$p}USER and {$p}TOKEN.");
        }
        $sources[] = [
            'name'       => $name,
            'host'       => $host,
            'port'       => (int) (getenv($p . 'PORT') ?: 2087),
            'user'       => $user,
            'token'      => $token,
            'verify_tls' => getenv($p . 'INSECURE') !== '1',
        ];
    }
    return $sources;
}

/* ------------------------------------------------------------------------- */
/* WHM / cPanel API                                                          */
/* ------------------------------------------------------------------------- */

/**
 * One WHM JSON-API call (POST). Retries with exponential backoff on network
 * errors, 429 and 5xx. Throws on persistent failure.
 */
function whm(array $src, string $function, array $params = [], int $attempts = 4): array {
    if ($function !== 'cpanel') {
        $params = ['api.version' => 1] + $params;
    }
    $url = sprintf('https://%s:%d/json-api/%s', $src['host'], $src['port'], $function);

    $last = '';
    for ($i = 0; $i < $attempts; $i++) {
        usleep(DELAY_MS * 1000);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => $src['verify_tls'],
            CURLOPT_SSL_VERIFYHOST => $src['verify_tls'] ? 2 : 0,
            CURLOPT_HTTPHEADER     => ["Authorization: whm {$src['user']}:{$src['token']}"],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $last = "curl: $err";
        } elseif ($code === 429 || $code >= 500) {
            $last = "HTTP $code";
        } elseif ($code === 401 || $code === 403) {
            throw new RuntimeException("HTTP $code (check API token / reseller ACLs)");
        } else {
            $json = json_decode($body, true);
            if (!is_array($json)) {
                throw new RuntimeException("HTTP $code, response was not JSON");
            }
            return $json;
        }
        sleep(2 ** $i); // 1s, 2s, 4s, 8s
    }
    throw new RuntimeException("gave up after $attempts attempts ($last)");
}

/** UAPI call proxied through WHM. @return array{ok:bool,data:mixed,error:string} */
function uapi(array $src, string $user, string $module, string $func, array $args = []): array {
    try {
        $r = whm($src, 'uapi_cpanel', [
            'cpanel.user'     => $user,
            'cpanel.module'   => $module,
            'cpanel.function' => $func,
        ] + $args);
    } catch (Throwable $e) {
        return ['ok' => false, 'data' => null, 'error' => $e->getMessage()];
    }

    $u = $r['data']['uapi'] ?? null;
    if (!is_array($u)) {
        return ['ok' => false, 'data' => null, 'error' => (string) ($r['metadata']['reason'] ?? 'unexpected response')];
    }
    $errors = array_filter((array) ($u['errors'] ?? []));
    return [
        'ok'    => ((int) ($u['status'] ?? 0)) === 1,
        'data'  => $u['data'] ?? null,
        'error' => implode('; ', array_map('strval', $errors)),
    ];
}

/** cPanel API2 call proxied through WHM's `cpanel` function. @return array{ok:bool,error:string} */
function api2(array $src, string $user, string $module, string $func, array $args = []): array {
    try {
        $r = whm($src, 'cpanel', [
            'cpanel_jsonapi_user'       => $user,
            'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module'     => $module,
            'cpanel_jsonapi_func'       => $func,
        ] + $args);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $res = $r['cpanelresult'] ?? [];
    if (!empty($res['error'])) {
        return ['ok' => false, 'error' => (string) $res['error']];
    }
    $ok = ((int) ($res['event']['result'] ?? 0)) === 1;
    return ['ok' => $ok, 'error' => $ok ? '' : (string) ($res['data'][0]['reason'] ?? 'API2 call failed')];
}

/* ------------------------------------------------------------------------- */
/* Helpers                                                                   */
/* ------------------------------------------------------------------------- */

/** @return array{ok:bool,entries:array<string,string>,error:string} name => type */
function ls(array $src, string $user, string $dir): array {
    $r = uapi($src, $user, 'Fileman', 'list_files', ['dir' => $dir]);
    if (!$r['ok']) {
        return ['ok' => false, 'entries' => [], 'error' => $r['error']];
    }
    $entries = [];
    foreach ((array) $r['data'] as $row) {
        $name = $row['file'] ?? $row['name'] ?? null;
        if (is_string($name)) {
            $entries[$name] = (string) ($row['type'] ?? '');
        }
    }
    return ['ok' => true, 'entries' => $entries, 'error' => ''];
}

function is_wordpress(array $entries): bool {
    return isset($entries['wp-content']) && (isset($entries['wp-includes']) || isset($entries['wp-config.php']));
}

function same_content(string $a, string $b): bool {
    $n = static fn(string $s): string => trim(str_replace("\r\n", "\n", $s));
    return $n($a) === $n($b);
}

/**
 * domains_data returns main_domain / addon_domains / sub_domains with a
 * `documentroot` each (as lists or hashes depending on version), so walk it
 * generically. @return array<string,string> docroot => domain
 */
function extract_docroots(mixed $data): array {
    $out  = [];
    $walk = function (mixed $node) use (&$walk, &$out): void {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['documentroot']) && is_string($node['documentroot'])) {
            $dom = (string) ($node['domain'] ?? $node['servername'] ?? '');
            if ($dom !== '' && $dom[0] !== '*' && !isset($out[$node['documentroot']])) {
                $out[$node['documentroot']] = $dom;
            }
            return;
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };
    $walk($data);
    return $out;
}

/** Request /?author=1 (+ cache-buster) and say whether the mu-plugin is live. */
function probe(string $domain): string {
    $url = 'https://' . $domain . '/?author=1&gg_probe=' . bin2hex(random_bytes(4));
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'GG-mu-plugin-deploy/1.0',
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return match (true) {
        $code === 404 => 'verify-ok(404)',
        $code >= 500  => "VERIFY-BROKEN($code)",
        $code === 0   => 'verify-unreachable',
        default       => "verify-not-effective($code)",
    };
}

/**
 * @return array{0:string,1:string}|null  [action, detail], or null if the docroot isn't WordPress
 */
function process_docroot(array $src, string $user, string $docroot, string $file, string $content, bool $dry, bool $remove): ?array {
    $root = ls($src, $user, $docroot);
    if (!$root['ok']) {
        return ['error', 'list docroot: ' . $root['error']];
    }
    if (!is_wordpress($root['entries'])) {
        return null;
    }

    $wpContent = rtrim($docroot, '/') . '/wp-content';
    $muDir     = $wpContent . '/mu-plugins';

    $wc = ls($src, $user, $wpContent);
    if (!$wc['ok']) {
        return ['error', 'list wp-content: ' . $wc['error']];
    }
    $muExists = isset($wc['entries']['mu-plugins']);

    $files = [];
    if ($muExists) {
        $mu = ls($src, $user, $muDir);
        if (!$mu['ok']) {
            return ['error', 'list mu-plugins: ' . $mu['error']];
        }
        $files = $mu['entries'];
    }
    $exists = isset($files[$file]);

    /* ---- remove mode ---- */
    if ($remove) {
        if (!$exists) {
            return ['absent', ''];
        }
        if ($dry) {
            return ['would-remove', ''];
        }
        $r = api2($src, $user, 'Fileman', 'fileop', [
            'op' => 'unlink',
            'sourcefiles' => "$muDir/$file",
            'doubledecode' => 0,
        ]);
        return $r['ok'] ? ['removed', ''] : ['error', 'unlink: ' . $r['error']];
    }

    /* ---- install / update ---- */
    if ($exists) {
        $cur = uapi($src, $user, 'Fileman', 'get_file_content', ['dir' => $muDir, 'file' => $file]);
        if ($cur['ok'] && same_content((string) ($cur['data']['content'] ?? ''), $content)) {
            return ['up-to-date', ''];
        }
    }
    if ($dry) {
        return [($exists ? 'would-update' : 'would-install'), $muExists ? '' : 'mu-plugins dir missing, would create'];
    }

    if (!$muExists) {
        $m = api2($src, $user, 'Fileman', 'mkdir', [
            'path' => $wpContent,
            'name' => 'mu-plugins',
            'permissions' => '0755',
        ]);
        if (!$m['ok']) {
            return ['error', 'mkdir mu-plugins: ' . $m['error']];
        }
    }

    $w = uapi($src, $user, 'Fileman', 'save_file_content', [
        'dir' => $muDir,
        'file' => $file,
        'content' => $content,
    ]);
    if (!$w['ok']) {
        return ['error', 'save: ' . $w['error']];
    }

    $back = uapi($src, $user, 'Fileman', 'get_file_content', ['dir' => $muDir, 'file' => $file]);
    if (!$back['ok'] || !same_content((string) ($back['data']['content'] ?? ''), $content)) {
        return ['error', 'write-mismatch (read-back differs or failed)'];
    }
    return [$exists ? 'updated' : 'installed', ''];
}

/* ------------------------------------------------------------------------- */
/* Main                                                                      */
/* ------------------------------------------------------------------------- */

$opts = getopt('', ['dry-run', 'remove', 'verify', 'source:', 'user:', 'limit:', 'file:', 'delay:', 'help']);
if ($opts === false || isset($opts['help'])) {
    echo "See the header of this file for usage.\n";
    exit(0);
}

$dry     = isset($opts['dry-run']);
$remove  = isset($opts['remove']);
$verify  = isset($opts['verify']);
$onlySrc = isset($opts['source']) ? array_map('trim', explode(',', (string) $opts['source'])) : [];
$onlyUsr = isset($opts['user'])   ? array_map('trim', explode(',', (string) $opts['user']))   : [];
$limit   = isset($opts['limit'])  ? (int) $opts['limit'] : 0;
define('DELAY_MS', isset($opts['delay']) ? max(0, (int) $opts['delay']) : 250);

$pluginPath = (string) ($opts['file'] ?? DEFAULT_PLUGIN);
$pluginName = basename($pluginPath);
$content    = '';

if (!$remove) {
    if (!is_readable($pluginPath)) {
        fail("Plugin file not readable: $pluginPath");
    }
    // Local syntax check. Not a substitute for the host PHP versions, which is
    // why the plugin itself is written to stay PHP 5.6-compatible.
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($pluginPath) . ' 2>&1', $lintOut, $lintRc);
    if ($lintRc !== 0) {
        fail("Plugin failed php -l:\n" . implode("\n", $lintOut));
    }
    $content = str_replace("\r\n", "\n", (string) file_get_contents($pluginPath));
}

// Prevent overlapping runs.
$lock = fopen(sys_get_temp_dir() . '/deploy_mu_plugin.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('Another deploy_mu_plugin run is in progress.', 2);
}

$reportPath = output_path('mu-plugin-deploy-' . date('Ymd-His') . '.csv');
$report     = fopen($reportPath, 'w');
fputcsv($report, ['source', 'user', 'domain', 'docroot', 'action', 'detail', 'verify']);

echo ($dry ? '[DRY RUN] ' : '') . ($remove ? 'Removing ' : 'Deploying ') . $pluginName . "\n\n";

$stats    = [];
$accounts = 0;
$nonWp    = 0;
$bump     = function (string $k) use (&$stats): void {
    $stats[$k] = ($stats[$k] ?? 0) + 1;
};
$emit = function (array $src, string $user, string $domain, string $docroot, string $action, string $detail, string $v) use ($report, $bump): void {
    fputcsv($report, [$src['name'], $user, $domain, $docroot, $action, $detail, $v]);
    $bump($action);
    if (str_starts_with($v, 'VERIFY-BROKEN')) {
        $bump('verify-broken');
    }
    printf("%-8s %-14s %-34s %-16s %s %s\n", $src['name'], $user, $domain, $action, $v, $detail);
};

foreach (load_sources() as $src) {
    if ($onlySrc && !in_array($src['name'], $onlySrc, true)) {
        continue;
    }

    try {
        $list = whm($src, 'listaccts');
    } catch (Throwable $e) {
        fwrite(STDERR, "[{$src['name']}] listaccts failed: {$e->getMessage()}\n");
        $bump('source-error');
        continue;
    }

    foreach ((array) ($list['data']['acct'] ?? []) as $acct) {
        $user = (string) ($acct['user'] ?? '');
        if ($user === '' || ($onlyUsr && !in_array($user, $onlyUsr, true))) {
            continue;
        }
        if ($limit > 0 && $accounts >= $limit) {
            break 2;
        }
        $accounts++;

        if (!empty($acct['suspended'])) {
            $emit($src, $user, (string) ($acct['domain'] ?? ''), '', 'skipped-suspended', '', '');
            continue;
        }

        $dd = uapi($src, $user, 'DomainInfo', 'domains_data');
        if (!$dd['ok']) {
            $emit($src, $user, (string) ($acct['domain'] ?? ''), '', 'error', 'domains_data: ' . $dd['error'], '');
            continue;
        }

        foreach (extract_docroots($dd['data']) as $docroot => $domain) {
            $res = process_docroot($src, $user, $docroot, $pluginName, $content, $dry, $remove);
            if ($res === null) {
                $nonWp++;
                continue;
            }
            [$action, $detail] = $res;

            $v = '';
            if ($verify && !$dry && !$remove && in_array($action, ['installed', 'updated', 'up-to-date'], true)) {
                $v = probe($domain);
            }
            $emit($src, $user, $domain, $docroot, $action, $detail, $v);
        }
    }
}

fclose($report);

echo "\n--- Summary ---\n";
echo "Accounts processed: $accounts   Non-WordPress docroots skipped: $nonWp\n";
ksort($stats);
foreach ($stats as $k => $n) {
    echo str_pad($k, 20) . $n . "\n";
}
echo "Report: $reportPath\n";

$bad = ($stats['error'] ?? 0) + ($stats['source-error'] ?? 0) + ($stats['verify-broken'] ?? 0);
exit($bad > 0 ? 1 : 0);
