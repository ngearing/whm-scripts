import https from 'https';
import dotenv from 'dotenv';
import { getPHP } from './getPHP';
import { setPHP } from './setPHP';

// ==========================================
// 1. CONFIGURATION CONFIG SETTINGS
// ==========================================

// Load environment variables if you prefer (uncomment below and set in .env file)
dotenv.config();
const WHM_URL = process.env.whm_url;
const WHM_USER = process.env.whm_user;
const API_TOKEN = process.env.api_token;

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

            let phpVersion = getPHP(username, domain);

            const oldVersions = ['ea-php56', 'ea-php70', 'ea-php71', 'ea-php72', 'ea-php73', 'ea-php74', 'ea-php80', 'ea-php81', 'ea-php82'];
            const targetVersion = 'ea-php83'; // The version you want to upgrade to

            if (oldVersions.includes(phpVersion)) {
                console.log(`--> ${username} is using an outdated PHP version (${phpVersion}). Attemping upgrade to ${targetVersion}.`);

                await setPHP(username, domain, targetVersion);
            }
        }
    }
    catch (error) {
        console.error('Master script critical connection error:', error.message);
    }
}

runAutomation();
