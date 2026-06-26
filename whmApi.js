export async function listAccounts({ WHM_URL, headers, agent }, searchType, search) {
    const url = `${WHM_URL}/json-api/listaccts?api.version=1`;
    const response = await fetch(url, { headers, agent });
    const data = await response.json();
    return data?.data?.acct || [];
}

export async function getAccount(config, username) {
    const accounts = await listAccounts(config, 'user', username);
    return accounts.find(account => account.user === username);
}

export async function getPHP({ WHM_URL, headers, agent }, username, domain) {
    const url = `${WHM_URL}/json-api/uapi_cpanel?api.version=1`
        + `&cpanel.user=${encodeURIComponent(username)}`
        + `&cpanel.module=LangPHP`
        + `&cpanel.function=php_get_vhost_versions`;

    const response = await fetch(url, { headers, agent });
    const uapiData = await response.json();
    const vhostList = uapiData?.data?.uapi?.data || [];

    let phpVersion = 'Not Defined';
    if (Array.isArray(vhostList)) {
        const primaryVhost = vhostList.find(v => v.vhost === domain);
        if (primaryVhost) {
            phpVersion = primaryVhost.version;
        } else if (vhostList.length > 0) {
            phpVersion = vhostList[0].version;
        }
    }

    console.log(`${username.padEnd(15)} | ${domain.padEnd(35)} | ${phpVersion}`);
    return phpVersion;
}

export async function setPHP({ WHM_URL, headers, agent }, username, domain, targetVersion) {
    const url = `${WHM_URL}/json-api/uapi_cpanel?api.version=1`
        + `&cpanel.user=${encodeURIComponent(username)}`
        + `&cpanel.module=LangPHP`
        + `&cpanel.function=php_set_vhost_versions`;

    const body = new URLSearchParams({
        vhost: domain,
        version: targetVersion
    }).toString();

    const response = await fetch(url, { method: 'POST', headers, agent, body });
    const data = await response.json();
    const result = data?.data?.uapi;

    if (result?.status === 0) {
        console.error(`--> FAILED for ${username}: ${result?.errors?.[0] ?? 'Unknown error'}`);
        return false;
    }

    console.log(`--> Success: ${username} (${domain}) set to ${targetVersion}`);
    return true;
}

export async function getWHMFunctions({ WHM_URL, headers, agent }) {
    const url = `${WHM_URL}/json-api/applist?api.version=1`;

    const response = await fetch(url, { headers, agent });
    const data = await response.json();
    console.log('WHM FUNCTIONS:', JSON.stringify(data, null, 2));
}

export async function getLangPHPFunctions({ WHM_URL, headers, agent }, username) {
    const url = `${WHM_URL}/json-api/get_available_uapi_functions?api.version=1&module=LangPHP`;

    const response = await fetch(url, { headers, agent });
    const data = await response.json();
    console.log('LANGPHP FUNCTIONS:', JSON.stringify(data, null, 2));
}

export async function getModules({ WHM_URL, headers, agent }, username) {
    // Try these module/function combinations one at a time
    const attempts = [
        { module: 'LangPHP', func: 'php_get_vhost_versions' },        // we know this works
        { module: 'LangPHP', func: 'php_ini_set_user_basic_overrides' },
        { module: 'LangPHP', func: 'php_ini_get_directives' },
        { module: 'LangPHP', func: 'php_ini_set_directives' },
        { module: 'LangPHP', func: 'php_get_installed_versions' },
        { module: 'PhpFpm', func: 'get_vhost_config' },
        { module: 'Extensions', func: 'list' },
        { module: 'CpPhpFpm', func: 'get_vhost_config' },
    ];

    for (const attempt of attempts) {
        const url = `${WHM_URL}/json-api/uapi_cpanel?api.version=1`
            + `&cpanel.user=${username}`
            + `&cpanel.module=${attempt.module}`
            + `&cpanel.function=${attempt.func}`;

        const response = await fetch(url, { method: 'POST', headers, agent });
        const data = await response.json();
        const result = data?.data?.uapi;
        const status = result?.status === 1 ? '✅ OK' : `❌ ${result?.errors?.[0]}`;
        console.log(`${attempt.module}::${attempt.func} → ${status}`);
    }
}

export async function getExtensions({ WHM_URL, headers, agent }, username, domain) {
    const url = `${WHM_URL}/json-api/uapi_cpanel?api.version=1`
        + `&cpanel.user=${username}`
        + `&cpanel.module=LangPHP`
        + `&cpanel.function=php_ini_get_user_basic_overrides`;

    const body = new URLSearchParams({
        'vhost': domain
    }).toString();

    const response = await fetch(url, { method: 'POST', headers, agent, body });
    const data = await response.json();

    console.log('RAW EXTENSIONS:', JSON.stringify(data, null, 2));
}

async function createUserSession(username, { WHM_URL, headers, agent }) {
    const url = `${WHM_URL}/json-api/create_user_session?api.version=1`
        + `&user=${username}`
        + `&service=cpaneld`;

    const response = await fetch(url, { headers, agent });
    const data = await response.json();

    return data?.data?.url; // Returns a one-time login URL with a session token
}

async function callCpanelUAPI({ WHM_URL, headers, agent }, sessionUrl, module, func, params = {}) {
    // Use cached session or init a new one
    const session = cachedSession || await initSession(sessionUrl);
    const { cpanelBase, sessionToken, cpsession } = session;

    const body = new URLSearchParams(params).toString();
    const url = `${cpanelBase}/${sessionToken}/execute/${module}/${func}`;
    console.log('Calling:', url);

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Cookie': cpsession
        },
        agent,
        body
    });

    const text = await response.text();
    console.log('RAW TEXT:', text.substring(0, 300));

    try {
        return JSON.parse(text);
    } catch {
        console.error('Got HTML instead of JSON');
        return null;
    }
}

// Test multiple LangPHP functions to see what's available
async function probeLangPHP(sessionUrl, config) {
    const functions = [
        'php_get_vhost_versions',
        'php_get_installed_versions',
        'php_set_vhost_versions',
        'php_ini_get_directives',
        'php_ini_set_directives',
        'php_ini_get_user_paths',
        'php_ini_set_user_basic_overrides',
        'php_ini_get_user_basic_overrides',
        'php_get_vhost_versions',
        'php_get_system_default_version',
        'get_php_version',
        'get_directives',
        'set_directives',
    ];

    for (const func of functions) {
        const data = await callCpanelUAPI(config, sessionUrl, 'LangPHP', func, {});
        const status = data?.status === 1 ? '✅ OK' : `❌ ${data?.errors?.[0]}`;
        console.log(`LangPHP::${func} → ${status}`);
    }
}

async function probeModules(sessionUrl, config) {
    const attempts = [
        { module: 'PHP', func: 'get_directives' },
        { module: 'PHP', func: 'set_directives' },
        { module: 'Ini', func: 'get_directives' },
        { module: 'Ini', func: 'set_directives' },
        { module: 'PhpIni', func: 'get_directives' },
        { module: 'PhpIni', func: 'set_directives' },
        { module: 'Features', func: 'list' },
        { module: 'CpExtensions', func: 'list' },
    ];

    for (const a of attempts) {
        const data = await callCpanelUAPI(config, sessionUrl, a.module, a.func, {});
        const status = data?.status === 1 ? '✅ OK' : `❌ ${data?.errors?.[0]}`;
        console.log(`${a.module}::${a.func} → ${status}`);
    }
}

// Store session data once, reuse across calls
let cachedSession = null;

async function initSession(sessionUrl, { WHM_URL, headers, agent }) {
    const urlObj = new URL(sessionUrl);
    const cpanelBase = `${urlObj.protocol}//${urlObj.host}`;
    const sessionToken = urlObj.pathname.split('/').find(p => p.startsWith('cpsess'));

    const loginResponse = await fetch(sessionUrl, {
        method: 'GET',
        agent,
        redirect: 'manual'
    });

    const rawCookies = loginResponse.headers.get('set-cookie') || '';
    const cpsession = rawCookies
        .split(',')
        .map(c => c.trim())
        .find(c => c.startsWith('cpsession='))
        ?.split(';')[0];

    console.log('cpsession cookie:', cpsession);

    const redirectLocation = loginResponse.headers.get('location');
    if (redirectLocation) {
        const activateUrl = `${cpanelBase}${redirectLocation}`;
        console.log('Activating session at:', activateUrl);
        await fetch(activateUrl, {
            method: 'GET',
            headers: { 'Cookie': cpsession },
            agent,
            redirect: 'manual'
        });
    }

    cachedSession = { cpanelBase, sessionToken, cpsession };
    return cachedSession;
}

export async function testUserSession(username, domain, config) {
    const sessionUrl = await createUserSession(username, config);
    console.log('Session URL:', sessionUrl);

    // Init session once
    await initSession(sessionUrl, config);

    // Now all calls reuse cachedSession
    await probeLangPHP(sessionUrl, config);
    await probeModules(sessionUrl, config);
}
