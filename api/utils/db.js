const { kv: vercelKV } = require('@vercel/kv');
const fs = require('fs');
const path = require('path');

const localDbPath = path.join(__dirname, '../../local_db.json');

const hasVercelKV = () => {
    return !!(process.env.KV_REST_API_URL && process.env.KV_REST_API_TOKEN);
};

const isDbConnected = () => {
    return true; // We always have a DB now (either Vercel KV or Local JSON)
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
            try {
                data = JSON.parse(fs.readFileSync(localDbPath, 'utf8'));
            } catch(e) {}
        }
        data[key] = value;
        fs.writeFileSync(localDbPath, JSON.stringify(data, null, 2), 'utf8');
    }
};

const kv = {
    async get(key) {
        if (hasVercelKV()) return await vercelKV.get(key);
        return await localKV.get(key);
    },
    async set(key, value) {
        if (hasVercelKV()) return await vercelKV.set(key, value);
        return await localKV.set(key, value);
    }
};

module.exports = { kv, isDbConnected };
