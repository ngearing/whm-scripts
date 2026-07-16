import { getPHP, listAccounts, setPHP } from './whmApi.js';
import { config, TARGET_USER } from './config.js';

async function runAutomation() {
    try {
        console.log('Connecting to WHM and fetching your 200+ user accounts...');

        const accounts = await listAccounts(config);

        if (accounts.length === 0) {
            console.log('No accounts found or check your credentials/permissions.');
            return;
        }

        console.log(`Found ${accounts.length} accounts. Starting PHP version lookup...\n`);
        console.log(`${'USER'.padEnd(15)} | ${'PRIMARY DOMAIN'.padEnd(35)} | HTTP STATUS | ${'PHP VERSION'}`);
        console.log('-'.repeat(70));

        // Loop through each account sequentially
        for (const account of accounts) {
            const username = account.user;
            const domain = account.domain;

            // Check website for HTTP/PHP errors by sending a HEAD request to the domain.
            let httpStatus = 'Unknown';
            await fetch(`https://${domain}`, { method: 'HEAD' })
                .then(response => {
                    httpStatus = response.status;
                })
                .catch(error => {
                    httpStatus = 'Error';
                });

            let phpVersion = await getPHP(config, username, domain);

            console.log(`${username.padEnd(15)} | ${domain.padEnd(35)} | ${httpStatus} | ${phpVersion}`);

            const oldVersions = ['ea-php56', 'ea-php70', 'ea-php71', 'ea-php72', 'ea-php73', 'ea-php74', 'ea-php80', 'ea-php81', 'ea-php82', 'ea-php85', 'alt-php85'];
            const targetVersion = 'ea-php83'; // The version you want to upgrade to

            if (oldVersions.includes(phpVersion)) {
                console.log(`--> ${username} is using an outdated PHP version (${phpVersion}). Attemping upgrade to ${targetVersion}.`);

                await setPHP(config, username, domain, targetVersion);
            }
        }
    }
    catch (error) {
        console.error('Master script critical connection error:', error.message);
    }
}

runAutomation();
