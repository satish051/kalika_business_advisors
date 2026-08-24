# Kalika Business Advisors - Admin Portal

A secure, serverless administration portal built for **Kalika Business Advisors**. This project provides an administrative dashboard deployed on Vercel utilizing Serverless Functions.

## 🚀 Features

- **Serverless Architecture:** Built entirely on Vercel Serverless Functions (`/api`).
- **Secure Authentication:** JWT-based stateless authentication combined with `bcryptjs` for secure password hashing.
- **Dynamic Views:** Server-Side Rendered (SSR) pages using EJS templating engine.
- **Data & File Storage:** 
  - Integration with **Vercel KV** (Redis) for fast, structured key-value data storage and session management.
  - Integration with **Vercel Blob** for seamless asset and file uploads.
- **Form Handling:** Uses `busboy` for robust multipart/form-data parsing.

## 🛠 Tech Stack

- **Runtime:** Node.js
- **Deployment:** Vercel (Serverless)
- **Templating:** EJS
- **Database & Storage:** Vercel KV (Redis) & Vercel Blob
- **Security:** `jsonwebtoken` (JWT), `bcryptjs`

## 📁 Project Structure

```text
├── api/                  # Vercel Serverless Functions (Backend logic)
│   ├── admin/            # Admin routes (login, dashboard, settings, logout)
│   └── utils/            # Shared utilities (e.g., auth checks)
├── views/                # EJS Templates (Frontend UI)
│   └── admin/            # Admin pages (login.ejs, dashboard.ejs, settings.ejs)
├── vercel.json           # Vercel routing configurations and rewrites
└── package.json          # Node.js dependencies
```

## ⚙️ Local Development

To run this project locally, you need [Node.js](https://nodejs.org/) and the [Vercel CLI](https://vercel.com/docs/cli) installed on your system.

1. **Clone the repository:**
   ```bash
   git clone https://github.com/satish051/kalika_business_advisors.git
   cd kalika_business_advisors
   ```

2. **Install dependencies:**
   ```bash
   npm install
   ```

3. **Set up Environment Variables:**
   Link your local project to your Vercel project to automatically pull down the necessary development environment variables:
   ```bash
   vercel link
   vercel env pull .env.local
   ```
   *(Ensure you have your Vercel KV tokens, Vercel Blob tokens, and a `JWT_SECRET` configured in your Vercel project dashboard).*

4. **Run the development server:**
   ```bash
   vercel dev
   ```
   The local admin panel will be accessible at `http://localhost:3000/admin/login`.

## 🌐 Deployment

This project is configured for zero-configuration deployment on **Vercel**. Pushing to the `main` branch will automatically trigger a Vercel deployment if connected to your repository. 

Routing is strictly handled via `vercel.json`, which rewrites user-friendly URLs (like `/admin/login`) directly to the serverless functions (`/api/admin/login`).
