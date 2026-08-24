const ejs = require('ejs');
const path = require('path');
const bcrypt = require('bcryptjs');
const { createClient } = require('@vercel/kv');
const kv = createClient({
    url: process.env.KV_REST_API_URL || process.env.UPSTASH_REDIS_REST_URL,
    token: process.env.KV_REST_API_TOKEN || process.env.UPSTASH_REDIS_REST_TOKEN,
});
const { validateSession } = require('../utils/auth');

module.exports = async (req, res) => {
    const session = await validateSession(req);
    if (!session) {
        return res.writeHead(302, { Location: '/admin/login' }).end();
    }

    let error_msg = '';
    let success_msg = '';

    let authData = (await kv.get('auth_json')) || null;

    if (req.method === 'POST') {
        const body = await new Promise((resolve) => {
            let data = '';
            req.on('data', chunk => data += chunk);
            req.on('end', () => resolve(new URLSearchParams(data)));
        });

        const current = body.get('current_password') || '';
        const newPass = body.get('new_password') || '';
        const confirm = body.get('confirm_password') || '';

        try {
            if (!(await bcrypt.compare(current, authData.password_hash))) {
                throw new Error("Current password is incorrect.");
            }
            if (newPass !== confirm) {
                throw new Error("New passwords do not match.");
            }
            if (newPass.length < 12) throw new Error("Password must be at least 12 characters.");
            if (!/[A-Z]/.test(newPass)) throw new Error("Password must contain at least one uppercase letter.");
            if (!/[a-z]/.test(newPass)) throw new Error("Password must contain at least one lowercase letter.");
            if (!/[0-9]/.test(newPass)) throw new Error("Password must contain at least one number.");
            if (!/[^A-Za-z0-9]/.test(newPass)) throw new Error("Password must contain at least one special character.");

            for (const oldHash of authData.password_history || []) {
                if (await bcrypt.compare(newPass, oldHash)) {
                    throw new Error("You cannot reuse any of your last 5 passwords.");
                }
            }

            if (!authData.password_history) authData.password_history = [];
            authData.password_history.push(authData.password_hash);
            if (authData.password_history.length > 5) {
                authData.password_history.shift();
            }

            authData.password_hash = await bcrypt.hash(newPass, 10);
            authData.must_change_password = false;
            authData.password_changed_at = new Date().toISOString();

            await kv.set('auth_json', authData);
            success_msg = "Password updated successfully.";
        } catch (e) {
            error_msg = e.message;
        }
    }

    const templatePath = path.join(__dirname, '../../views/admin/settings.ejs');
    ejs.renderFile(templatePath, { error_msg, success_msg, auth_data: authData }, (err, str) => {
        if (err) return res.status(500).send('Template error: ' + err);
        res.setHeader('Content-Type', 'text/html');
        res.status(200).send(str);
    });
};
