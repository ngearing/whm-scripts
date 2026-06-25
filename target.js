import https from 'https';
import dotenv from 'dotenv';
import { getPHP } from './getPHP.js';
import { setPHP } from './setPHP.js';

// ==========================================
// 1. CONFIGURATION CONFIG SETTINGS
// ==========================================

// Load environment variables if you prefer (uncomment below and set in .env file)
dotenv.config();
const WHM_URL = process.env.whm_url;
const WHM_USER = process.env.whm_user;
const API_TOKEN = process.env.api_token;
const TARGET_USER = 'aspace00'; // Testing just this single user

const headers = {
    'Authorization': `whm ${WHM_USER}:${API_TOKEN}`,
    'Content-Type': 'application/x-www-form-urlencoded'
};
const agent = new https.Agent({ rejectUnauthorized: true });
const config = { WHM_URL, headers, agent };

async function debugPHP() {
    const username = TARGET_USER;

    const getAccountUrl = `${WHM_URL}/json-api/listaccts?api.version=1&searchtype=user&search=${username}`;
    const getAccountResponse = await fetch(getAccountUrl, { headers, agent });
    const getAccountData = await getAccountResponse.json();
    const accountList = getAccountData?.data?.acct || [];
    const account = accountList.find(v => v.user === username);
    const domain = account?.domain;

    const phpVersion = await getPHP(username, domain, config);

    const oldVersions = ['ea-php56', 'ea-php70', 'ea-php71', 'ea-php72', 'ea-php73', 'ea-php74', 'ea-php80', 'ea-php81', 'ea-php82'];
    const targetVersion = 'ea-php83'; // The version you want to upgrade to

    if (oldVersions.includes(phpVersion)) {
        console.log(`--> ${username} is using an outdated PHP version (${phpVersion}). Attemping upgrade to ${targetVersion}.`);

        await setPHP(username, domain, targetVersion, config);
    }
}

debugPHP();
