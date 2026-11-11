# Neybor pre-launch website

A separate, public React, TypeScript, Vite, and Tailwind CSS website for
introducing Neybor and collecting customer/provider waitlist registrations. It
is not the mobile marketplace and does not create user accounts.

```powershell
npm install
Copy-Item .env.example .env.local
npm run dev
npm run build
```

Configure `VITE_API_BASE_URL` (default `http://localhost:8000/api/v1`). The site
submits to `POST /waitlist`; Laravel normalizes email, validates provider
details, rate-limits submissions, prevents duplicate entries per audience, and
persists the registration. Configure the API CORS allowlist for the deployed
site origin.

The website includes landing, about, how-it-works, benefits, service categories,
FAQs, contact, privacy, terms, and distinct customer/provider signup options.
It provides submission loading, error, success, and duplicate feedback. It does
not request device-location or notification permissions.
