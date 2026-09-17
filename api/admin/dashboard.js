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
        hero_bg: "amazing-panorama-from-gokyo-ri-viewpoint-mount-everest-lho-la-nuptse-lhotse-peaks-sagarmatha-national-park-nepalgolden-sunrise-with-clear-blue-sky-mt-everest-peak-view.webp",
        founder_img: "Gemini_Generated_Image_mebqh2mebqh2mebq.webp",
        video_url: "https://www.youtube-nocookie.com/embed/ScMzIvxBSi4?controls=0&rel=0&autoplay=0&mute=1&loop=1&playlist=ScMzIvxBSi4",
        notice: { enabled: false, title: "Important Notice", message: "Welcome to our newly updated platform.", button_text: "Acknowledge" },
        practice_areas: [
            { title: "Accounting & Finance", description: "Rigorous reporting...", icon: "fa-chart-pie", division: "Division A", image: "https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?q=80&w=800&auto=format&fit=crop" },
            { title: "Tax & Legal", description: "Company registration...", icon: "fa-scale-balanced", division: "Division B", image: "https://images.unsplash.com/photo-1589829085413-56de8ae18c73?q=80&w=800&auto=format&fit=crop" },
            { title: "Governance & Policy", description: "Corporate bylaws...", icon: "fa-building-columns", division: "Division C", image: "https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=800&auto=format&fit=crop" }
        ]
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
                if (fields.clients_json) {
                    try {
                        data.clients = JSON.parse(fields.clients_json);
                    } catch (e) {
                        console.error('Failed to parse clients_json', e);
                    }
                } else {
                    data.clients = data.clients || [];
                }
                if (fields.practice_areas_json) {
                    try {
                        data.practice_areas = JSON.parse(fields.practice_areas_json);
                    } catch (e) {
                        console.error('Failed to parse practice_areas_json', e);
                    }
                } else {
                    data.practice_areas = data.practice_areas || [];
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
                    // For local file DB, we'll just base64 encode or we MUST have Vercel blob.
                    // Wait, Vercel Blob requires process.env.BLOB_READ_WRITE_TOKEN.
                    // Since we're trying to support local file DB, Vercel Blob WILL THROW AN ERROR locally if no token!
                    let blobUrl = '';
                    if (process.env.BLOB_READ_WRITE_TOKEN) {
                        const blob = await put(upload.filename, upload.buffer, { access: 'public' });
                        blobUrl = blob.url;
                    } else {
                        // Fallback to base64 for local dev without Blob token
                        const b64 = upload.buffer.toString('base64');
                        blobUrl = `data:${upload.mimeType || 'image/jpeg'};base64,${b64}`;
                    }
                    
                    if (upload.name === 'hero_bg_file') data.hero_bg = blobUrl;
                    if (upload.name === 'founder_img_file') data.founder_img = blobUrl;
                    
                    if (upload.name.startsWith('client_logo_file_')) {
                        const idx = parseInt(upload.name.split('_').pop());
                        if (data.clients && data.clients[idx]) {
                            data.clients[idx].logo_url = blobUrl;
                        }
                    }
                    if (upload.name.startsWith('practice_img_file_')) {
                        const idx = parseInt(upload.name.split('_').pop());
                        if (data.practice_areas && data.practice_areas[idx]) {
                            data.practice_areas[idx].image = blobUrl;
                        }
                    }
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
