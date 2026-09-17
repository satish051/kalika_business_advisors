const { kv: vercelKV } = require('@vercel/kv');
const { createClient } = require('redis');
const fs = require('fs');
const path = require('path');

const localDbPath = path.join(__dirname, '../../local_db.json');

const hasVercelKV = () => !!(process.env.KV_REST_API_URL && process.env.KV_REST_API_TOKEN);
const hasTcpRedis = () => !!process.env.REDIS_URL;

let tcpRedisClient = null;
async function getTcpClient() {
    if (!tcpRedisClient) {
        tcpRedisClient = createClient({ url: process.env.REDIS_URL });
        tcpRedisClient.on('error', (err) => console.error('Redis Client Error', err));
        await tcpRedisClient.connect();
    }
    return tcpRedisClient;
}

const isDbConnected = () => {
    if (hasVercelKV()) return true;
    if (hasTcpRedis()) return true;
    if (process.env.VERCEL) return false; // Vercel filesystem is read-only, cannot fallback
    return true; // Local JSON fallback
};

// Local JSON file database adapter
const localKV = {
    async get(key) {
        if (!fs.existsSync(localDbPath)) return null;
        try {
            const data = JSON.parse(fs.readFileSync(localDbPath, 'utf8'));
            return data[key] || null;
        } catch (e) {
            return null;
        }
    },
    async set(key, value) {
        let data = {};
        if (fs.existsSync(localDbPath)) {
            try { data = JSON.parse(fs.readFileSync(localDbPath, 'utf8')); } catch(e) {}
        }
        data[key] = value;
        try { fs.writeFileSync(localDbPath, JSON.stringify(data, null, 2), 'utf8'); } catch(e) { console.error('Failed to write to local DB:', e); }
    }
};

const kv = {
    async get(key) {
        if (hasVercelKV()) return await vercelKV.get(key);
        if (hasTcpRedis()) {
            const client = await getTcpClient();
            const val = await client.get(key);
            try { return val ? JSON.parse(val) : null; } catch(e) { return val; }
        }
        return await localKV.get(key);
    },
    async set(key, value) {
        if (hasVercelKV()) return await vercelKV.set(key, value);
        if (hasTcpRedis()) {
            const client = await getTcpClient();
            const strValue = typeof value === 'object' ? JSON.stringify(value) : value;
            return await client.set(key, strValue);
        }
        return await localKV.set(key, value);
    }
};

module.exports = { kv, isDbConnected };
