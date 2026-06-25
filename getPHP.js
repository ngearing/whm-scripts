export async function getPHP(username, domain, { WHM_URL, headers, agent }) {
    const getUapiUrl = `${WHM_URL}/json-api/uapi_cpanel?api.version=1`
        + `&cpanel.user=${username}`
        + `&cpanel.module=LangPHP`
        + `&cpanel.function=php_get_vhost_versions`;

    try {
        const response = await fetch(getUapiUrl, { headers, agent });
        const uapiData = await response.json();

        // EXTRACTING THE SYSTEM VERSION ARRAY
        const vhostList = uapiData?.data?.uapi?.data || [];
        let phpVersion = 'Not Defined';

        // Look through the server array to extract the record matching the primary domain
        if (Array.isArray(vhostList)) {
            const primaryVhost = vhostList.find(v => v.vhost === domain);
            if (primaryVhost) {
                phpVersion = primaryVhost.version;
            } else if (vhostList.length > 0) {
                // Fallback: grab the first configuration version found on the profile
                phpVersion = vhostList[0].version;
            }
        }

        console.log(`${username.padEnd(15)} | ${domain.padEnd(35)} | ${phpVersion}`);

        return phpVersion;
    } catch (err) {
        console.log(`${username.padEnd(15)} | ${domain.padEnd(35)} | ERROR: ${err.message}`);
    }
}
