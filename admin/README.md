# Neybor Admin

Responsive React, TypeScript, Vite, and Tailwind CSS operations dashboard. It
uses the Laravel API for all analytics and records; it has no seeded dashboard
statistics or public administrator registration.

## Run locally

```powershell
npm install
Copy-Item .env.example .env.local
npm run dev
npm run build
```

Set `VITE_API_BASE_URL` to the Laravel API base URL (default:
`http://localhost:8000/api/v1`). This value is public configuration, never a
secret. Backend CORS must allow the dashboard origin.

Sign in using an administrator account provisioned through the backend's
protected bootstrap process. Admin API routes enforce Sanctum authentication,
active-account checks, administrator role checks, validation, and rate limits.
The frontend checks the signed-in role but does not replace server
authorization. Administrative state-changing actions require a reason and are
recorded in the audit log.

## Included views

Live overview, customer/provider search and status filters, provider approvals,
service categories and moderation, booking monitoring, reviews, own
notifications, reports, waitlist registrations, settings, and audit history.
Disputes are explicitly marked outside the current MVP.

Payment settings only save preferred gateways and business-policy values.
Paystack/Flutterwave checkout, transactions, commissions, refunds, and earnings
settlement are not implemented. `payments_enabled` remains false and no fake
payment success or revenue is displayed. See
[`../backend/docs/PAYMENTS.md`](../backend/docs/PAYMENTS.md).
