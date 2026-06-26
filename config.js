import https from 'https';
import dotenv from 'dotenv';

dotenv.config();

const WHM_URL = process.env.whm_url;
const WHM_USER = process.env.whm_user;
const API_TOKEN = process.env.api_token;
export const TARGET_USER = process.env.target_user;

if (!WHM_URL || !WHM_USER || !API_TOKEN) {
    throw new Error('Missing required WHM environment variables: whm_url, whm_user, api_token');
}

const headers = {
    'Authorization': `whm ${WHM_USER}:${API_TOKEN}`,
    'Content-Type': 'application/x-www-form-urlencoded'
};

const agent = new https.Agent({ rejectUnauthorized: true });

export const config = { WHM_URL, headers, agent };
