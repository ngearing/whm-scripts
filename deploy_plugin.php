<?php

/**
 * deploy_plugin.php
 *
 * Uploads one or more single-file WordPress plugins into wp-content/mu-plugins/
 * or wp-content/plugins/ of every WordPress install on every cPanel account of
 * one WHM reseller source. Replaces the old deploy_mu_plugin.php.
 *
 * Flow per account:
 *   UAPI DomainInfo::domains_data                (all docroots)
 *   -> per docroot: UAPI Fileman::list_files     (is it WordPress? target dir present?)
 *                   API2 Fileman::mkdir          (only if the target dir is missing)
 *                   UAPI Fileman::save_file_content, then read back + compare
 *
 * Everything goes through WHM's uapi_cpanel / cpanel proxies with the reseller
 * API token, so no per-account cPanel passwords or sessions are needed.
 * Credentials come from .env / the environment (see bootstrap.php); one source
 * per run, e.g.  DOTENV_PATH=.env.shock-2 php deploy_plugin.php ...
 *
 * USAGE
 *   php deploy_plugin.php --file=gg-user-enumeration-protection.php --dry-run
 *   php deploy_plugin.php --file=gg-user-enumeration-protection.php --user=someacct --verify
 *   php deploy_plugin.php --all --limit=5 --verify               # everything in plugins/
 *   php deploy_plugin.php --file=my-plugin.php --target=plugins  # normal plugins folder
 *   php deploy_plugin.php --file=gg-litespeed-nocache.php --remove --dry-run
 *
 * OPTIONS
 *   --file=a.php,b.php  Files to deploy. A bare name is looked up in ./plugins/
 *                       (a path that exists is used as given).
 *   --all               Deploy every *.php in ./plugins/.
 *   --target=DIR        mu-plugins (default) or plugins.
 *                       mu-plugins load automatically. A file put in plugins/ is
 *                       only UPLOADED - it still has to be activated in wp-admin.
 *   --dry-run           Read-only. Report what would be installed/updated/removed.
 *   --remove            Delete the file(s) from the target instead of installing.
 *   --verify            After install, request the site: 5xx = BROKEN (roll back that
 *                       site), unreachable is flagged. If gg-user-enumeration-protection.php
 *                       is among the files, /?author=1 must also return 404. Only
 *                       meaningful for mu-plugins (a plugin that isn't active does
 *                       nothing), so it is ignored for --target=plugins.
 *   --user=a,b          Only these cPanel usernames.
 *   --limit=N           Stop after N accounts (use for a first small batch).
 *   --delay=MS          Pause between API calls (default 250).
 *
 * Single files only - a plugin that is a folder (assets, includes/) can't be
 * pushed with this.
 *
 * Idempotent: identical files are reported "up-to-date" and not rewritten, so
 * re-running after a failure or after editing a plugin is safe. Files are
 * checked with `php -l` locally first, and the repo's plugins are kept PHP 5.6
 * compatible because some accounts still run old ea-php versions.
 *
 * Report: output/plugin-deploy-<source>-<target>-YYYYmmdd-HHMMSS.csv
 */

require __DIR__ . '/bootstrap.php';
define_whm_config();
require __DIR__ . '/lib/whm.php';

const PLUGINS_DIR = __DIR__ . '/plugins';
const TARGETS     = ['mu-plugins', 'plugins'];
const ENUM_PLUGIN = 'gg-user-enumeration-protection.php';

function fail(string $msg, int $code = 1): never {
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit($code);
}

/* ------------------------------------------------------------------------- */
/* WHM / cPanel API (thin wrappers over lib/whm.php)                         */
/* ------------------------------------------------------------------------- */

/** UAPI call via WHM. @return array{ok:bool,data:mixed,error:string} */
function uapi(string $user, string $module, string $func, array $args = [], bool $post = false): array {
    try {
        usleep(DELAY_MS * 1000);
        $r = with_retries(fn() => uapi_call($user, $module, $func, $args, $post), 3);
    } catch (Throwable $e) {
        return ['ok' => false, 'data' => null, 'error' => $e->getMessage()];
    }

    if (!isset($r['status'])) {
        return ['ok' => false, 'data' => null, 'error' => 'unexpected response'];
    }
    $errors = array_filter((array) ($r['errors'] ?? []));
    return [
        'ok'    => ((int) $r['status']) === 1,
        'data'  => $r['data'] ?? null,
        'error' => implode('; ', array_map('strval', $errors)),
    ];
}

/** cPanel API2 call via WHM's `cpanel` function. @return array{ok:bool,error:string} */
function api2(string $user, string $module, string $func, array $args = []): array {
    try {
        usleep(DELAY_MS * 1000);
        $r = with_retries(fn() => whm_api_call('cpanel', [], [
            'cpanel_jsonapi_user'       => $user,
            'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module'     => $module,
            'cpanel_jsonapi_func'       => $func,
        ] + $args), 3);
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
function ls(string $user, string $dir): array {
    $r = uapi($user, 'Fileman', 'list_files', ['dir' => $dir]);
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

function http_status(string $url): int {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'GG-plugin-deploy/2.0',
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/** Does the site still load, and (if asked) is the author probe blocked? */
function probe(string $domain, bool $checkAuthor): string {
    $bust = bin2hex(random_bytes(4));

    $home = http_status("https://{$domain}/?gg_probe={$bust}");
    if ($home >= 500) {
        return "VERIFY-BROKEN({$home})";
    }
    if ($home === 0) {
        return 'verify-unreachable';
    }
    if (!$checkAuthor) {
        return "verify-ok({$home})";
    }

    $author = http_status("https://{$domain}/?author=1&gg_probe={$bust}");
    return match (true) {
        $author === 404 => 'verify-ok(404)',
        $author >= 500  => "VERIFY-BROKEN({$author})",
        $author === 0   => 'verify-unreachable',
        default         => "verify-not-effective({$author})",
    };
}

/**
 * @param array<string,string> $files name => content (empty content when removing)
 * @return array<int, array{0:string,1:string,2:string}>|null
 *         rows of [file, action, detail], or null if the docroot isn't WordPress
 */
function process_docroot(string $user, string $docroot, array $files, string $target, bool $dry, bool $remove): ?array {
    $root = ls($user, $docroot);
    if (!$root['ok']) {
        return [['', 'error', 'list docroot: ' . $root['error']]];
    }
    if (!is_wordpress($root['entries'])) {
        return null;
    }

    $wpContent = rtrim($docroot, '/') . '/wp-content';
    $dir       = $wpContent . '/' . $target;

    $wc = ls($user, $wpContent);
    if (!$wc['ok']) {
        return [['', 'error', 'list wp-content: ' . $wc['error']]];
    }
    $dirExists = isset($wc['entries'][$target]);

    $present = [];
    if ($dirExists) {
        $d = ls($user, $dir);
        if (!$d['ok']) {
            return [['', 'error', "list {$target}: " . $d['error']]];
        }
        $present = $d['entries'];
    }

    $rows     = [];
    $dirReady = $dirExists;

    foreach ($files as $file => $content) {
        $exists = isset($present[$file]);

        if ($exists && $present[$file] === 'dir') {
            $rows[] = [$file, 'error', 'a folder with this name already exists in ' . $target];
            continue;
        }

        /* ---- remove mode ---- */
        if ($remove) {
            if (!$exists) {
                $rows[] = [$file, 'absent', ''];
            } elseif ($dry) {
                $rows[] = [$file, 'would-remove', ''];
            } else {
                $r = api2($user, 'Fileman', 'fileop', [
                    'op' => 'unlink',
                    'sourcefiles' => "{$dir}/{$file}",
                    'doubledecode' => 0,
                ]);
                $rows[] = $r['ok'] ? [$file, 'removed', ''] : [$file, 'error', 'unlink: ' . $r['error']];
            }
            continue;
        }

        /* ---- install / update ---- */
        if ($exists) {
            $cur = uapi($user, 'Fileman', 'get_file_content', ['dir' => $dir, 'file' => $file]);
            if ($cur['ok'] && same_content((string) ($cur['data']['content'] ?? ''), $content)) {
                $rows[] = [$file, 'up-to-date', ''];
                continue;
            }
        }
        if ($dry) {
            $rows[] = [$file, $exists ? 'would-update' : 'would-install', $dirReady ? '' : "{$target} dir missing, would create"];
            continue;
        }

        if (!$dirReady) {
            $m = api2($user, 'Fileman', 'mkdir', [
                'path' => $wpContent,
                'name' => $target,
                'permissions' => '0755',
            ]);
            if (!$m['ok']) {
                $rows[] = [$file, 'error', "mkdir {$target}: " . $m['error']];
                continue;
            }
            $dirReady = true;
        }

        $w = uapi($user, 'Fileman', 'save_file_content', [
            'dir' => $dir,
            'file' => $file,
            'content' => $content,
        ], true);
        if (!$w['ok']) {
            $rows[] = [$file, 'error', 'save: ' . $w['error']];
            continue;
        }

        $back = uapi($user, 'Fileman', 'get_file_content', ['dir' => $dir, 'file' => $file]);
        if (!$back['ok'] || !same_content((string) ($back['data']['content'] ?? ''), $content)) {
            $rows[] = [$file, 'error', 'write-mismatch (read-back differs or failed)'];
            continue;
        }
        $rows[] = [$file, $exists ? 'updated' : 'installed', ''];
    }

    return $rows;
}

/**
 * --file / --all -> [remote filename => local path (null when only removing a
 * file that no longer exists locally)].
 *
 * @return array<string,?string>
 */
function resolve_files(array $args, bool $remove): array {
    $paths = [];

    if (isset($args['all'])) {
        foreach (glob(PLUGINS_DIR . '/*.php') ?: [] as $p) {
            $paths[] = $p;
        }
    }
    if (isset($args['file'])) {
        if (!is_string($args['file']) || trim($args['file']) === '') {
            fail('--file needs a value, e.g. --file=gg-litespeed-nocache.php');
        }
        foreach (explode(',', $args['file']) as $f) {
            $f = trim($f);
            if ($f === '') {
                continue;
            }
            if (is_file($f)) {
                $paths[] = $f;
            } elseif (is_file(PLUGINS_DIR . '/' . basename($f))) {
                $paths[] = PLUGINS_DIR . '/' . basename($f);
            } elseif ($remove) {
                $paths[] = basename($f); // removing something we no longer ship
            } else {
                fail("File not found: {$f} (looked in the current folder and " . PLUGINS_DIR . ')');
            }
        }
    }

    $files = [];
    foreach ($paths as $p) {
        $name = basename($p);
        if (!preg_match('/^[A-Za-z0-9._-]+\.php$/', $name)) {
            fail("Unsupported file name '{$name}' (letters, digits, . _ - and a .php extension only).");
        }
        $local = is_file($p) ? realpath($p) : null;
        if (isset($files[$name]) && $files[$name] !== $local) {
            fail("Two different files are both called {$name}.");
        }
        $files[$name] = $local;
    }
    return $files;
}

function usage(): void {
    $available = array_map('basename', glob(PLUGINS_DIR . '/*.php') ?: []);
    echo "Usage: php deploy_plugin.php (--file=a.php,b.php | --all) [--target=mu-plugins|plugins]\n";
    echo "                             [--dry-run] [--remove] [--verify] [--user=a,b] [--limit=N] [--delay=MS]\n\n";
    echo "Files in " . PLUGINS_DIR . ":\n";
    foreach ($available as $f) {
        echo "  {$f}\n";
    }
    echo "\nSee the header of this file for details.\n";
}

/* ------------------------------------------------------------------------- */
/* Main                                                                      */
/* ------------------------------------------------------------------------- */

$args = cli_args($argv);
if (isset($args['help'])) {
    usage();
    exit(0);
}

$target = $args['target'] ?? 'mu-plugins';
if (!is_string($target) || !in_array($target, TARGETS, true)) {
    fail('--target must be one of: ' . implode(', ', TARGETS));
}

$dry      = isset($args['dry-run']);
$remove   = isset($args['remove']);
$verify   = isset($args['verify']);
$onlyUsr  = isset($args['user']) && is_string($args['user']) ? array_filter(array_map('trim', explode(',', $args['user']))) : [];
$limit    = isset($args['limit']) ? (int) $args['limit'] : 0;
define('DELAY_MS', isset($args['delay']) ? max(0, (int) $args['delay']) : 250);

$localFiles = resolve_files($args, $remove);
if (!$localFiles) {
    usage();
    fail('Nothing to deploy: pass --file=NAME[,NAME] or --all.');
}

/** @var array<string,string> $contents name => LF-normalised content */
$contents = [];
foreach ($localFiles as $name => $path) {
    if ($remove) {
        $contents[$name] = '';
        continue;
    }
    // Local syntax check. Not a substitute for the host PHP versions, which is
    // why the plugins are written to stay PHP 5.6-compatible.
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg((string) $path) . ' 2>&1', $lintOut, $lintRc);
    if ($lintRc !== 0) {
        fail("{$name} failed php -l:\n" . implode("\n", $lintOut));
    }
    $text = str_replace("\r\n", "\n", (string) file_get_contents((string) $path));
    if (trim($text) === '') {
        fail("{$name} is empty.");
    }
    $contents[$name] = $text;
}

if ($verify && $target !== 'mu-plugins' && !$remove) {
    echo "Note: --verify is ignored for --target={$target} (an inactive plugin changes nothing on the site).\n";
    $verify = false;
}
$checkAuthor = isset($contents[ENUM_PLUGIN]);

// Prevent overlapping runs against the same reseller.
$lock = fopen(sys_get_temp_dir() . '/deploy_plugin_' . safe_filename_part(WHM_SOURCE_LABEL) . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('Another deploy_plugin run is in progress for ' . WHM_SOURCE_LABEL . '.', 2);
}

$reportPath = output_path(sprintf(
    'plugin-deploy-%s-%s-%s.csv',
    safe_filename_part(WHM_SOURCE_LABEL),
    $target,
    date('Ymd-His')
));
$report = fopen($reportPath, 'w');
fputcsv($report, ['source', 'user', 'domain', 'docroot', 'file', 'action', 'detail', 'verify'], ',', '"', '\\');

echo ($dry ? '[DRY RUN] ' : '') . ($remove ? 'Removing ' : 'Deploying ') . implode(', ', array_keys($contents))
    . " -> wp-content/{$target}/ on " . WHM_SOURCE_LABEL . "\n";
if ($target === 'plugins' && !$remove) {
    echo "(plugins/ files are uploaded only - activate them in wp-admin or with WP Toolkit.)\n";
}
echo "\n";

$stats    = [];
$accounts = 0;
$nonWp    = 0;
$bump     = function (string $k) use (&$stats): void {
    $stats[$k] = ($stats[$k] ?? 0) + 1;
};
$emit = function (string $user, string $domain, string $docroot, string $file, string $action, string $detail, string $v) use ($report, $bump): void {
    fputcsv($report, [WHM_SOURCE_LABEL, $user, $domain, $docroot, $file, $action, $detail, $v], ',', '"', '\\');
    $bump($action);
    printf("%-14s %-34s %-28s %-14s %s %s\n", $user, $domain, $file, $action, $v, $detail);
};

try {
    $acctList = whm_list_accounts();
} catch (Throwable $e) {
    fail('listaccts failed: ' . $e->getMessage());
}

foreach ($acctList as $acct) {
    $user = (string) ($acct['user'] ?? '');
    if ($user === '' || ($onlyUsr && !in_array($user, $onlyUsr, true))) {
        continue;
    }
    if ($limit > 0 && $accounts >= $limit) {
        break;
    }
    $accounts++;

    if (!empty($acct['suspended'])) {
        $emit($user, (string) ($acct['domain'] ?? ''), '', '', 'skipped-suspended', '', '');
        continue;
    }

    $dd = uapi($user, 'DomainInfo', 'domains_data');
    if (!$dd['ok']) {
        $emit($user, (string) ($acct['domain'] ?? ''), '', '', 'error', 'domains_data: ' . $dd['error'], '');
        continue;
    }

    foreach (extract_docroots($dd['data']) as $docroot => $domain) {
        $rows = process_docroot($user, $docroot, $contents, $target, $dry, $remove);
        if ($rows === null) {
            $nonWp++;
            continue;
        }

        $v = '';
        if ($verify && !$dry && !$remove) {
            foreach ($rows as $row) {
                if (in_array($row[1], ['installed', 'updated', 'up-to-date'], true)) {
                    $v = probe($domain, $checkAuthor);
                    break;
                }
            }
            if (str_starts_with($v, 'VERIFY-BROKEN')) {
                $bump('verify-broken');
            }
        }

        foreach ($rows as [$file, $action, $detail]) {
            $ok = in_array($action, ['installed', 'updated', 'up-to-date'], true);
            $emit($user, $domain, $docroot, $file, $action, $detail, $ok ? $v : '');
        }
    }
}

fclose($report);

echo "\n--- Summary ---\n";
echo "Accounts processed: {$accounts}   Non-WordPress docroots skipped: {$nonWp}\n";
ksort($stats);
foreach ($stats as $k => $n) {
    echo str_pad($k, 20) . $n . "\n";
}
echo "Report: {$reportPath}\n";

$bad = ($stats['error'] ?? 0) + ($stats['verify-broken'] ?? 0);
exit($bad > 0 ? 1 : 0);
