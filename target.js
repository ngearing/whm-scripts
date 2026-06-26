import { getAccount, testUserSession, getLangPHPFunctions, getPHP, setPHP } from './whmApi.js';
import { config, TARGET_USER } from './config.js';

async function debugPHP() {
    const username = TARGET_USER;
    const account = await getAccount(config, username);
    const domain = account?.domain;

    if (!domain) {
        console.error(`Unable to determine primary domain for user ${username}.`);
        return;
    }

    await testUserSession(username, domain, config);

    const phpVersion = await getPHP(config, username, domain);

    const oldVersions = ['ea-php56', 'ea-php70', 'ea-php71', 'ea-php72', 'ea-php73', 'ea-php74', 'ea-php80', 'ea-php81', 'ea-php82', 'alt-php85'];
    const targetVersion = 'ea-php83'; // The version you want to upgrade to

    if (oldVersions.includes(phpVersion)) {
        console.log(`--> ${username} is using an outdated PHP version (${phpVersion}). Attempting upgrade to ${targetVersion}.`);
        await setPHP(config, username, domain, targetVersion);
    } else {
        console.log(`--> ${username} is already on ${phpVersion}. No upgrade needed.`);
    }
}

debugPHP();
