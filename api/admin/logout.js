const { clearSession } = require('../utils/auth');

module.exports = (req, res) => {
    clearSession(res);
    res.writeHead(302, { Location: '/admin/login' }).end();
};
