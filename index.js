import https from 'https';
import dotenv from 'dotenv';

// ==========================================
// 1. CONFIGURATION CONFIG SETTINGS
// ==========================================

// Load environment variables if you prefer (uncomment below and set in .env file)
dotenv.config();
const WHM_URL = process.env.WHM_URL;
const WHM_USER = process.env.USER;
const API_TOKEN = process.env.API_TOKEN;

const headers = {
    'Authorization': `whm ${WHM_USER}:${API_TOKEN}`,
    'Content-Type': 'application/x-www-form-urlencoded'
};

// Create an agent that ignores self-signed SSL warnings if your WHM uses a temporary certificate
const agent = new https.Agent({ rejectUnauthorized: true });

async function runAutomation() {
    try {
        console.log('Connecting to WHM and fetching your 200+ user accounts...');

        // Fetch all accounts assigned to your reseller profile
        const listAccountsUrl = `${WHM_URL}/json-api/listaccts?api.version=1`;
        const listResponse = await fetch(listAccountsUrl, { headers, agent });
        const listData = await listResponse.json();

        const accounts = listData?.data?.acct || [];

        if (accounts.length === 0) {
            console.log('No accounts found or check your credentials/permissions.');
            return;
        }

        console.log(`Found ${accounts.length} accounts. Starting PHP version lookup...\n`);
        console.log(`${'USER'.padEnd(15)} | ${'PRIMARY DOMAIN'.padEnd(35)} | ${'PHP VERSION'}`);
        console.log('-'.repeat(70));

        // Loop through each account sequentially
        for (const account of accounts) {
            const username = account.user;
            const domain = account.domain;

            // Targeting cPanel's LangPHP module
            const uapiUrl = `${WHM_URL}/json-api/uapi?api.version=1&user=${username}&module=LangPHP&function=php_get_vhost_versions`;

            try {
                const uapiResponse = await fetch(uapiUrl, { headers, agent });
                const uapiData = await uapiResponse.json();

                // EXTRACTING THE SYSTEM VERSION ARRAY
                const vhostList = uapiData?.result?.data?.vhosts || [];
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
            } catch (err) {
                console.log(`${username.padEnd(15)} | ${domain.padEnd(35)} | ERROR: ${err.message}`);
            }
        }

    } catch (error) {
        console.error('Master script critical connection error:', error.message);
    }
}

runAutomation();
