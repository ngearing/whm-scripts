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
const TARGET_USER = 'aspace00'; // Testing just this single user

const headers = { 'Authorization': `whm ${WHM_USER}:${API_TOKEN}` };
const agent = new https.Agent({ rejectUnauthorized: true });

async function debugPHP() {
    // const uapiUrl = `${WHM_URL}/json-api/uapi?api.version=1&user=${TARGET_USER}&module=LangPHP&function=php_get_vhost_versions`;
    const uapiUrl = `${WHM_URL}/json-api/uapi_cpanel?api.version=1&cpanel.user=${TARGET_USER}&cpanel.module=LangPHP&cpanel.function=php_get_vhost_versions`;
    try {
        const response = await fetch(uapiUrl, { headers, agent });
        const data = await response.json();

        console.log("=== RAW DATA RETURNED FROM YOUR SERVER ===");
        console.log(JSON.stringify(data, null, 4));
    } catch (err) {
        console.error('Error:', err.message);
    }
}
debugPHP();
