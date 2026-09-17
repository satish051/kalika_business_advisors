const ejs = require('ejs');
const path = require('path');
const { kv, isDbConnected } = require('./utils/db');

module.exports = async (req, res) => {
    const default_data = {
        hero_title: "A consulting firm for everything.",
        hero_description: "Chartered accountants, lawyers, policy drafters, environmental specialists, former senior officials, and veteran bankers.",
        hero_bg: "amazing-panorama-from-gokyo-ri-viewpoint-mount-everest-lho-la-nuptse-lhotse-peaks-sagarmatha-national-park-nepalgolden-sunrise-with-clear-blue-sky-mt-everest-peak-view.webp",
        founder_img: "Gemini_Generated_Image_mebqh2mebqh2mebq.webp",
        video_url: "https://www.youtube-nocookie.com/embed/ScMzIvxBSi4?controls=0&rel=0&autoplay=0&mute=1&loop=1&playlist=ScMzIvxBSi4",
        notice: { enabled: false, title: "Important Notice", message: "Welcome to our newly updated platform.", button_text: "Acknowledge" },
        practice_areas: [
            {
                title: "Accounting & Finance",
                description: "Rigorous reporting, statutory audit, financial outsourcing, and enterprise-grade digital systems architecture.",
                icon: "fa-chart-pie",
                division: "Division A",
                image: "https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?q=80&w=800&auto=format&fit=crop"
            },
            {
                title: "Tax & Legal",
                description: "Company registration, complex regulatory licensing, strategic taxation, and foreign investment structuring.",
                icon: "fa-scale-balanced",
                division: "Division B",
                image: "https://images.unsplash.com/photo-1589829085413-56de8ae18c73?q=80&w=800&auto=format&fit=crop"
            },
            {
                title: "Governance & Policy",
                description: "Corporate bylaws, high-level policy drafting, and executive regulatory advocacy, spearheaded by former officials.",
                icon: "fa-building-columns",
                division: "Division C",
                image: "https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=800&auto=format&fit=crop"
            }
        ]
    };
    
    let data = default_data;
    try {
        if (isDbConnected()) {
            const remote_data = await kv.get('site_data');
            if (remote_data) data = remote_data;
        }
    } catch (err) {
        console.error("KV Error:", err.message);
    }

    const templatePath = path.join(__dirname, '../views/index.ejs');
    
    res.setHeader("X-Content-Type-Options", "nosniff");
    res.setHeader("X-Frame-Options", "DENY");
    res.setHeader("Referrer-Policy", "strict-origin-when-cross-origin");
    if (process.env.NODE_ENV === 'production') {
        res.setHeader("Strict-Transport-Security", "max-age=31536000; includeSubDomains");
    }
    res.setHeader("Content-Security-Policy", "default-src 'self'; connect-src 'self' https://formspree.io; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://images.unsplash.com https://*.public.blob.vercel-storage.com; frame-src 'self' https://www.youtube-nocookie.com https://www.youtube.com https://player.vimeo.com;");

    ejs.renderFile(templatePath, { data }, (err, str) => {
        if (err) return res.status(500).send('Template error: ' + err);
        res.setHeader('Content-Type', 'text/html');
        res.status(200).send(str);
    });
};
