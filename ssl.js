import { listAccounts, createUserSession, initSession, callCpanelUAPI } from './whmApi.js';
import { config } from './config.js';

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function getExclusionsWithRetry(username, retries = 2) {
    for (let attempt = 0; attempt <= retries; attempt++) {
        try {
            const sessionUrl = await createUserSession(username, config);
            await initSession(sessionUrl);
            const data = await callCpanelUAPI(sessionUrl, 'SSL', 'get_autossl_excluded_domains', {});
            return data;
        } catch (err) {
            if (attempt < retries) {
                console.log(`  Retry ${attempt + 1} for ${username} after error: ${err.message}`);
                await sleep(1000 * (attempt + 1)); // backoff: 1s, 2s
            } else {
                throw err;
            }
        }
    }
}

async function checkAutoSSLExclusions() {
    try {
        console.log('Fetching accounts...');
        const accounts = await listAccounts(config);

        console.log(`\n${'USER'.padEnd(15)} | ${'DOMAIN'.padEnd(40)} | ${'EXCLUDED DOMAINS'}`);
        console.log('-'.repeat(90));

        for (const account of accounts) {
            const username = account.user;
            const domain = account.domain;

            try {
                const data = await getExclusionsWithRetry(username);
                const excluded = data?.data || [];
                const excludedList = excluded.length > 0
                    ? excluded.map(e => e.domain || e).join(', ')
                    : 'None';

                console.log(`${username.padEnd(15)} | ${domain.padEnd(40)} | ${excludedList}`);

            } catch (err) {
                console.log(`${username.padEnd(15)} | ${domain.padEnd(40)} | ERROR: ${err.message}`);
            }

            await sleep(500); // delay between accounts regardless of outcome
        }

        console.log('\nDone!');

    } catch (error) {
        console.error('Critical connection error:', error.message);
    }
}

checkAutoSSLExclusions();
