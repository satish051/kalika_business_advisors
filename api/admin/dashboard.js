const ejs = require('ejs');
const path = require('path');
const { kv, isDbConnected } = require('../utils/db');
const { put } = require('@vercel/blob');
const Busboy = require('busboy');
const { validateSession } = require('../utils/auth');

module.exports = async (req, res) => {
    const session = await validateSession(req);
    if (!session) {
        return res.writeHead(302, { Location: '/admin/login' }).end();
    }

    const authData = isDbConnected() ? (await kv.get('auth_json')) || {} : {};
    if (authData.must_change_password) {
        return res.writeHead(302, { Location: '/admin/settings' }).end();
    }

    const default_data = {
        hero_title: "A consulting firm for everything.",
        hero_description: "Chartered accountants, lawyers, policy drafters, environmental specialists, former senior officials, and veteran bankers.",
        hero_bg: "amazing-panorama-from-gokyo-ri-viewpoint-mount-everest-lho-la-nuptse-lhotse-peaks-sagarmatha-national-park-nepalgolden-sunrise-with-clear-blue-sky-mt-everest-peak-view.jpg",
        founder_img: "Gemini_Generated_Image_mebqh2mebqh2mebq.jpg",
        video_url: "https://www.youtube-nocookie.com/embed/ScMzIvxBSi4?controls=0&rel=0&autoplay=0&mute=1&loop=1&playlist=ScMzIvxBSi4",
        notice: { enabled: false, title: "Important Notice", message: "Welcome to our newly updated platform.", button_text: "Acknowledge" }
    };

    let data = isDbConnected() ? ((await kv.get('site_data')) || default_data) : default_data;
    let success_msg = '';
    let error_msg = '';
    
    if (!isDbConnected()) {
        error_msg = "Database not connected. Cannot save changes.";
    }

    if (req.method === 'POST' && isDbConnected()) {
        const busboy = Busboy({ headers: req.headers });
        const fields = {};
        const uploads = [];

        busboy.on('field', (name, val) => {
            fields[name] = val;
        });

        busboy.on('file', (name, file, info) => {
            const { filename, mimeType } = info;
            if (!filename) {
                file.resume();
                return;
            }
            const allowed = ['image/jpeg', 'image/png', 'image/webp'];
            if (!allowed.includes(mimeType)) {
                error_msg = "Only JPEG, PNG, and WebP are allowed.";
                file.resume();
                return;
            }
            const bufs = [];
            file.on('data', d => bufs.push(d));
            file.on('end', () => {
                uploads.push({ name, filename, buffer: Buffer.concat(bufs) });
            });
        });

        await new Promise((resolve) => {
            busboy.on('finish', resolve);
            req.pipe(busboy);
        });

        if (!error_msg) {
            try {
                if (fields.notices_json) {
                    try {
                        data.notices = JSON.parse(fields.notices_json);
                    } catch (e) {
                        console.error('Failed to parse notices_json', e);
                    }
                }
                data.hero_title = (fields.hero_title || '').substring(0, 100);
                data.hero_description = (fields.hero_description || '').substring(0, 500);
                
                const vUrl = fields.video_url || '';
                if (vUrl.includes('youtube.com') || vUrl.includes('youtu.be') || vUrl.includes('vimeo.com')) {
                    data.video_url = vUrl;
                } else {
                    throw new Error("Invalid Video URL. Only YouTube or Vimeo are allowed.");
                }

                data.notice.enabled = !!fields.notice_enabled;
                data.notice.title = (fields.notice_title || '').substring(0, 100);
                data.notice.message = (fields.notice_message || '').substring(0, 1000);
                data.notice.button_text = (fields.notice_button_text || '').substring(0, 50);

                for (const upload of uploads) {
                    const blob = await put(upload.filename, upload.buffer, { access: 'public' });
                    if (upload.name === 'hero_bg_file') data.hero_bg = blob.url;
                    if (upload.name === 'founder_img_file') data.founder_img = blob.url;
                }

                await kv.set('site_data', data);
                success_msg = "Settings securely updated in Vercel KV.";
            } catch (e) {
                error_msg = e.message;
            }
        }
    }

    const templatePath = path.join(__dirname, '../../views/admin/dashboard.ejs');
    ejs.renderFile(templatePath, { error_msg, success_msg, data, auth_data: authData }, (err, str) => {
        if (err) return res.status(500).send('Template error: ' + err);
        res.setHeader('Content-Type', 'text/html');
        res.status(200).send(str);
    });
};
