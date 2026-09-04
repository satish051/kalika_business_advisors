const express = require('express');
const path = require('path');
const app = express();

app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Serve static files from the root
app.use(express.static(__dirname));

// Function to map Vercel serverless functions to Express routes
function mapVercelRoute(expressApp, route, handlerPath) {
    expressApp.all(route, async (req, res) => {
        try {
            // Delete cache so we can edit files and see changes without restarting server
            delete require.cache[require.resolve(path.join(__dirname, handlerPath))];
            const handler = require(path.join(__dirname, handlerPath));
            await handler(req, res);
        } catch (err) {
            console.error(`Error executing ${handlerPath}:`, err);
            if (!res.headersSent) {
                res.status(500).send('Internal Server Error');
            }
        }
    });
}

// Map the rewrites from vercel.json
mapVercelRoute(app, '/', './api/index');
mapVercelRoute(app, '/admin/login', './api/admin/login');
mapVercelRoute(app, '/admin/dashboard', './api/admin/dashboard');
mapVercelRoute(app, '/admin/settings', './api/admin/settings');
mapVercelRoute(app, '/admin/logout', './api/admin/logout');

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
    console.log(`Local development server is running on http://localhost:${PORT}`);
});
