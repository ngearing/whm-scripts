export async function setPHP(username, domain, targetVersion, { WHM_URL, headers, agent }) {
    const setUapiUrl = `${WHM_URL}/json-api/uapi_cpanel?api.version=1`
        + `&cpanel.user=${username}`
        + `&cpanel.module=LangPHP`
        + `&cpanel.function=php_set_vhost_versions`;

    const body = new URLSearchParams({
        'vhost': domain,
        'version': targetVersion
    }).toString();

    try {
        const response = await fetch(setUapiUrl, { method: 'POST', headers, agent, body });
        const data = await response.json();

        const result = data?.data?.uapi;
        if (result?.status === 0) {
            console.error(`--> FAILED for ${username}: ${result?.errors?.[0] ?? 'Unknown error'}`);
            return;
        } else {
            console.log(`--> Success: ${username} (${domain}) set to ${targetVersion}`);
            return true;
        }
    } catch (error) {
        console.error(`Error setting PHP for ${username}:`, error.message);
    }
}
