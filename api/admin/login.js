const ejs = require('ejs');
const path = require('path');
const bcrypt = require('bcryptjs');
const { kv } = require('@vercel/kv');
const { validateSession, setSession, checkThrottle, recordFailedLogin, clearThrottle } = require('../utils/auth');

module.exports = async (req, res) => {
    const session = await validateSession(req);
    if (session) {
        return res.writeHead(302, { Location: '/admin/dashboard' }).end();
    }

    let error = '';

    if (req.method === 'POST') {
        // Collect body data
        const body = await new Promise((resolve) => {
            let data = '';
            req.on('data', chunk => data += chunk);
            req.on('end', () => resolve(new URLSearchParams(data)));
        });

        const ip = req.headers['x-forwarded-for'] || req.socket.remoteAddress || 'UNKNOWN';

        if (!(await checkThrottle(ip))) {
            error = 'Too many failed attempts. Please try again later.';
        } else {
            const username = body.get('username') || '';
            const password = body.get('password') || '';

            let authData = (await kv.get('auth_json')) || null;
            
            // Seed authData if first time
            if (!authData) {
                authData = {
                    username: 'admin',
                    password_hash: await bcrypt.hash('password', 10),
                    must_change_password: true,
                    password_history: []
                };
                await kv.set('auth_json', authData);
            }

            if (username === authData.username && await bcrypt.compare(password, authData.password_hash)) {
                await clearThrottle(ip);
                setSession(res, { admin_logged_in: true });
                
                if (authData.must_change_password) {
                    return res.writeHead(302, { Location: '/admin/settings' }).end();
                } else {
                    return res.writeHead(302, { Location: '/admin/dashboard' }).end();
                }
            } else {
                await recordFailedLogin(ip);
                error = 'Invalid username or password.';
            }
        }
    }

    const templatePath = path.join(__dirname, '../../views/admin/login.ejs');
    ejs.renderFile(templatePath, { error }, (err, str) => {
        if (err) return res.status(500).send('Template error: ' + err);
        res.setHeader('Content-Type', 'text/html');
        res.status(200).send(str);
    });
};
