const jwt = require('jsonwebtoken');
const { kv } = require('@vercel/kv');

const SECRET_KEY = process.env.APP_SECRET || 'fallback_secret_change_in_production';

// Simple cookie parser
function parseCookies(cookieHeader) {
    if (!cookieHeader) return {};
    return cookieHeader.split(';').reduce((acc, cookie) => {
        const [key, value] = cookie.trim().split('=');
        if (key && value) {
            acc[key] = decodeURIComponent(value);
        }
        return acc;
    }, {});
}

// Simple cookie serializer
function serializeCookie(name, val, options = {}) {
    let str = `${name}=${encodeURIComponent(val)}`;
    if (options.maxAge) str += `; Max-Age=${options.maxAge}`;
    if (options.path) str += `; Path=${options.path}`;
    if (options.httpOnly) str += '; HttpOnly';
    if (options.secure) str += '; Secure';
    if (options.sameSite) str += `; SameSite=${options.sameSite}`;
    return str;
}

async function validateSession(req) {
    const cookies = parseCookies(req.headers.cookie);
    const token = cookies.vercel_session;
    if (!token) return null;

    try {
        const decoded = jwt.verify(token, SECRET_KEY);
        if (decoded.admin_logged_in) {
            return decoded;
        }
    } catch (e) {
        return null;
    }
    return null;
}

function setSession(res, data) {
    const token = jwt.sign(data, SECRET_KEY, { expiresIn: '12h' });
    const cookieHeader = serializeCookie('vercel_session', token, {
        httpOnly: true,
        secure: process.env.NODE_ENV === 'production',
        sameSite: 'strict',
        maxAge: 43200,
        path: '/'
    });
    res.setHeader('Set-Cookie', cookieHeader);
}

function clearSession(res) {
    const cookieHeader = serializeCookie('vercel_session', '', {
        httpOnly: true,
        secure: process.env.NODE_ENV === 'production',
        sameSite: 'strict',
        maxAge: -1,
        path: '/'
    });
    res.setHeader('Set-Cookie', cookieHeader);
}

async function checkThrottle(ip) {
    const data = (await kv.get('throttle_json')) || {};
    if (data[ip]) {
        const lockedUntil = data[ip].locked_until || 0;
        if (Date.now() < lockedUntil) {
            return false;
        }
    }
    return true;
}

async function recordFailedLogin(ip) {
    const data = (await kv.get('throttle_json')) || {};
    if (!data[ip]) {
        data[ip] = { failed: 0, last_attempt: Date.now(), locked_until: 0 };
    }
    data[ip].failed += 1;
    data[ip].last_attempt = Date.now();
    const f = data[ip].failed;

    if (f >= 20) data[ip].locked_until = Date.now() + 30 * 60 * 1000;
    else if (f >= 11) data[ip].locked_until = Date.now() + 5 * 60 * 1000;
    else if (f >= 6) data[ip].locked_until = Date.now() + 30 * 1000;

    await kv.set('throttle_json', data);
}

async function clearThrottle(ip) {
    const data = (await kv.get('throttle_json')) || {};
    if (data[ip]) {
        delete data[ip];
        await kv.set('throttle_json', data);
    }
}

module.exports = {
    validateSession,
    setSession,
    clearSession,
    checkThrottle,
    recordFailedLogin,
    clearThrottle
};
