<?php

/**
 * bootstrap.php - shared setup for every script in this folder.
 *
 * Each script starts with:
 *
 *     require __DIR__ . '/bootstrap.php';
 *     define_whm_config();        // or define_synergy_config() / define_source_label()
 *
 * What this gives you:
 *
 *   .env loading     Reads KEY=VALUE lines from .env next to this file
 *                    (override the location with DOTENV_PATH - handy for one
 *                    file per reseller: DOTENV_PATH=.env.shock-2 php ...).
 *                    A variable already set in the real shell environment is
 *                    never overwritten, so `WHM_API_TOKEN=xxx php script.php`
 *                    or a CI secret still wins over .env.
 *
 *   Constants        DB_PATH     SQLite fleet DB   (env DB_PATH,    default ./websites.sqlite)
 *                    OUTPUT_DIR  CSV/JSONL reports (env OUTPUT_DIR, default ./output)
 *
 *   Config definers  define_whm_config()      WHM_HOST, WHM_PORT, WHM_USERNAME,
 *                                             WHM_API_TOKEN, WHM_SOURCE_LABEL
 *                    define_synergy_config()  SYNERGY_RESELLER_ID, SYNERGY_API_KEY
 *                    define_source_label()    WHM_SOURCE_LABEL only (for scripts
 *                                             that just read the local DB)
 *                    These exit with a clear message if a required value is
 *                    missing, instead of carrying on with a 'YOUR_API_TOKEN'
 *                    placeholder and failing later with a confusing API error.
 *
 *   Helpers          env(), output_path(), safe_filename_part(),
 *                    resolve_input_path(), cli_args()
 */

if (defined('BOOTSTRAP_LOADED')) {
    return;
}
define('BOOTSTRAP_LOADED', true);

// ---------------------------------------------------------------------
// .ENV LOADER - no Composer/phpdotenv dependency. Reads simple KEY=VALUE
// lines (optionally prefixed with `export `), skips blanks/comments and
// strips surrounding quotes. Never overwrites a variable that is already
// set in the real environment.
// ---------------------------------------------------------------------
function load_dotenv(string $path): void {
    if (!file_exists($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line); // also strips the \r from CRLF files
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (strlen($value) >= 2 && (
            ($value[0] === '"' && $value[-1] === '"') ||
            ($value[0] === "'" && $value[-1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

/** Returns an env value, or $default when it is unset or empty. */
function env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

/** Relative paths in config are resolved against this folder, not the cwd. */
function resolve_path(string $path): string {
    if ($path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path))) {
        return $path;
    }
    return __DIR__ . '/' . $path;
}

// A typo'd DOTENV_PATH should be loud, not a silent fallback to "no .env".
$dotenvPath = getenv('DOTENV_PATH');
if ($dotenvPath !== false && $dotenvPath !== '') {
    $dotenvPath = resolve_path($dotenvPath);
    if (!file_exists($dotenvPath)) {
        fwrite(STDERR, "DOTENV_PATH points to a file that does not exist: {$dotenvPath}\n");
        exit(1);
    }
} else {
    $dotenvPath = __DIR__ . '/.env';
}
load_dotenv($dotenvPath);
unset($dotenvPath);

define('DB_PATH', resolve_path(env('DB_PATH', 'websites.sqlite')));
define('OUTPUT_DIR', rtrim(resolve_path(env('OUTPUT_DIR', 'output')), '/'));

// ---------------------------------------------------------------------
// CONFIG DEFINERS
// ---------------------------------------------------------------------
function exit_missing_config(array $missing): never {
    fwrite(STDERR, "Missing required setting(s): " . implode(', ', $missing) . "\n");
    fwrite(STDERR, "Set them in " . __DIR__ . "/.env (copy .env.example to get started) or export them in your shell.\n");
    exit(1);
}

function require_env(array $keys): void {
    $missing = array_values(array_filter($keys, fn ($k) => env($k) === null));
    if ($missing) {
        exit_missing_config($missing);
    }
}

/**
 * The label every synced row is tagged with, so several reseller accounts
 * can share one DB. Explicit WHM_SOURCE_LABEL wins; otherwise "host:user".
 */
function resolve_source_label(): ?string {
    $label = env('WHM_SOURCE_LABEL');
    if ($label !== null) {
        return $label;
    }
    if (env('WHM_HOST') !== null && env('WHM_USERNAME') !== null) {
        return env('WHM_HOST') . ':' . env('WHM_USERNAME');
    }
    return null;
}

function define_source_label(): void {
    $label = resolve_source_label();
    if ($label === null) {
        exit_missing_config(['WHM_SOURCE_LABEL']);
    }
    define('WHM_SOURCE_LABEL', $label);
}

function define_whm_config(): void {
    require_env(['WHM_HOST', 'WHM_USERNAME', 'WHM_API_TOKEN']);

    define('WHM_HOST', env('WHM_HOST'));
    define('WHM_PORT', env('WHM_PORT', '2087'));
    define('WHM_USERNAME', env('WHM_USERNAME'));
    define('WHM_API_TOKEN', env('WHM_API_TOKEN'));
    define_source_label();
}

function define_synergy_config(): void {
    require_env(['SYNERGY_RESELLER_ID', 'SYNERGY_API_KEY']);

    define('SYNERGY_RESELLER_ID', env('SYNERGY_RESELLER_ID'));
    define('SYNERGY_API_KEY', env('SYNERGY_API_KEY'));
}

// ---------------------------------------------------------------------
// PATH + CLI HELPERS
// ---------------------------------------------------------------------

/** Makes a string safe to embed in a filename (source labels contain ':'). */
function safe_filename_part(string $s): string {
    return preg_replace('/[^A-Za-z0-9._-]/', '_', $s);
}

/** Full path for a report file inside OUTPUT_DIR, creating the dir if needed. */
function output_path(string $filename): string {
    if (!is_dir(OUTPUT_DIR) && !mkdir(OUTPUT_DIR, 0775, true) && !is_dir(OUTPUT_DIR)) {
        throw new RuntimeException('Could not create output directory: ' . OUTPUT_DIR);
    }
    return OUTPUT_DIR . '/' . $filename;
}

/**
 * For scripts that read a CSV another script produced: a bare filename that
 * does not exist in the cwd is looked up in OUTPUT_DIR. Anything with a
 * directory part is used exactly as given.
 */
function resolve_input_path(string $path): string {
    if (is_file($path)) {
        return $path;
    }
    if (!str_contains($path, '/') && is_file(OUTPUT_DIR . '/' . $path)) {
        return OUTPUT_DIR . '/' . $path;
    }
    return $path;
}

/** Parses --key=value and --flag arguments into an array. */
function cli_args(array $argv): array {
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
