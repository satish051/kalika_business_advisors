const { createClient } = require('redis');

let client = null;

async function getClient() {
    if (client) return client;
    if (!process.env.REDIS_URL && !process.env.KV_REST_API_URL && !process.env.UPSTASH_REDIS_REST_URL) {
        return null; // No database available
    }
    
    // If we have REDIS_URL from the new Vercel Redis integration
    if (process.env.REDIS_URL) {
        client = createClient({ url: process.env.REDIS_URL });
        await client.connect();
        return client;
    }
    
    return null; // Fallback for unsupported KV cases in this custom wrapper
}

const kv = {
    async get(key) {
        const c = await getClient();
        if (!c) return null;
        const val = await c.get(key);
        if (!val) return null;
        try {
            return JSON.parse(val);
        } catch(e) {
            return val;
        }
    },
    async set(key, value) {
        const c = await getClient();
        if (!c) return;
        const val = typeof value === 'object' ? JSON.stringify(value) : value;
        await c.set(key, val);
    }
};

const isDbConnected = () => {
    return !!(process.env.REDIS_URL || process.env.KV_REST_API_URL || process.env.UPSTASH_REDIS_REST_URL);
};

// Exporting kv wrapper
module.exports = { kv, isDbConnected };
