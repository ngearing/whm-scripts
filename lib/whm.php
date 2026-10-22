<?php

/**
 * lib/whm.php - WHM / UAPI helpers for scripts that talk to a reseller account.
 *
 * Requires define_whm_config() to have run first (see bootstrap.php).
 *
 * Used by upgrade_php_versions.php and check_autossl_exclusions.php. The
 * older scripts still carry their own copy of whm_api_call(); they can move
 * over to this file whenever you next touch them.
 *
 * Every function is wrapped in function_exists() so a test harness can
 * pre-define a fake whm_api_call() and exercise the scripts without a server.
 */

if (!function_exists('whm_api_call')) {
    /**
     * Calls a WHM JSON API function. Params go in the query string; pass
     * $post (even an empty array) to send the request as a POST with that
     * form body instead - needed for uapi_cpanel write operations.
     */
    function whm_api_call(string $function, array $params = [], ?array $post = null): array {
        // The `cpanel` function (API2 proxy) is not a whmapi1 call and does not
        // take api.version; every other function here is whmapi1.
        if ($function !== 'cpanel') {
            $params['api.version'] = 1;
        }
        $url = sprintf(
            'https://%s:%s/json-api/%s?%s',
            WHM_HOST,
            WHM_PORT,
            $function,
            http_build_query($params)
        );

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: whm ' . WHM_USERNAME . ':' . WHM_API_TOKEN],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($post !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);

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
}

if (!function_exists('uapi_call')) {
    /**
     * Runs a cPanel UAPI function as $username through WHM's uapi_cpanel
     * proxy (reseller ACLs don't grant the top-level equivalents).
     *
     * $args are plain parameters - no `cpanel.arg-` prefix. Reads send them
     * in the query string; writes ($post = true) send them as the POST body.
     *
     * @return array the UAPI result: ['status' => 1|0, 'data' => ..., 'errors' => ...]
     */
    function uapi_call(string $username, string $module, string $function, array $args = [], bool $post = false): array {
        $query = [
            'cpanel.user'     => $username,
            'cpanel.module'   => $module,
            'cpanel.function' => $function,
        ];

        $response = $post
            ? whm_api_call('uapi_cpanel', $query, $args)
            : whm_api_call('uapi_cpanel', array_merge($query, $args));

        return $response['data']['uapi'] ?? [];
    }
}

if (!function_exists('whm_list_accounts')) {
    /** Every cPanel account on the reseller (listaccts): user, domain, suspended, ... */
    function whm_list_accounts(): array {
        $response = whm_api_call('listaccts');
        return $response['data']['acct'] ?? [];
    }
}

if (!function_exists('with_retries')) {
    /**
     * Runs $fn, retrying on exceptions with a linear backoff
     * (1s, 2s, ... by default). Rethrows after the last attempt.
     */
    function with_retries(callable $fn, int $retries = 2, int $baseDelayMs = 1000): mixed {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $fn();
            } catch (Throwable $e) {
                if ($attempt >= $retries) {
                    throw $e;
                }
                fwrite(STDERR, '  retry ' . ($attempt + 1) . ' after error: ' . $e->getMessage() . "\n");
                usleep($baseDelayMs * 1000 * ($attempt + 1));
            }
        }
    }
}
