# NEYBOR

NEYBOR is a location-aware marketplace connecting customers with nearby service
professionals. The repository keeps each client and the API independently
installable:

| Project | Stack | Directory |
| --- | --- | --- |
| iOS and Android app | React Native, Expo, TypeScript | `mobile/` |
| REST API | PHP 8.3+, Laravel, MySQL | `backend/` |
| Operations dashboard | React, TypeScript, Tailwind CSS | `admin/` |
| Public pre-launch website | React, TypeScript, Tailwind CSS | `prelaunch/` |

Redis is used for cache and queues. Laravel Reverb provides WebSockets. The
mobile application uses Google Maps Platform and Expo Notifications (FCM/APNs
through Expo).

## Requirements

- Node.js 22.13+ and npm
- PHP 8.3+ and Composer
- MySQL 8+
- Redis 7+
- Expo development build tooling for native maps and push notifications
- Google Maps Platform API keys for Android and iOS

MySQL and Redis may be local services or managed instances. Keep credentials,
API keys, signing keys, and provider certificates out of source control.

## Initial setup

Install dependencies separately in each project:

```powershell
Set-Location mobile
npm install
Copy-Item .env.example .env

Set-Location ..\admin
npm install
Copy-Item .env.example .env.local

Set-Location ..\prelaunch
npm install
Copy-Item .env.example .env.local

Set-Location ..\backend
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
php artisan key:generate
php artisan reverb:install
```

Configure `backend/.env` with MySQL and Redis credentials before migrating.
`reverb:install` generates local Reverb credentials and enables the broadcaster;
the example defaults to the log broadcaster so Artisan remains usable before
credentials have been created.

```powershell
Set-Location backend
php artisan migrate
```

Start the API, queue worker, Reverb server, and clients in separate terminals:

```powershell
# backend/
php artisan serve --host=192.168.1.25
php artisan queue:work redis
php artisan reverb:start

# mobile/
npm run start

# admin/
npm run dev

# prelaunch/
npm run dev
```

The API health endpoint is `GET /api/v1/health`. Set `VITE_API_BASE_URL` for
the dashboard and `EXPO_PUBLIC_API_BASE_URL` for the mobile app when the API is
not available at their local defaults. Physical devices must use a reachable
LAN address; replace the sample IP in the command with your computer's LAN IP
and use that IP in `mobile/.env`. Only allow access on a trusted network.
Android emulators can also use `10.0.2.2` to reach the host.
The public pre-launch website runs separately from the mobile marketplace and
uses the same `VITE_API_BASE_URL` setting for its backend waitlist.

## Configuration

- **Maps:** Set `EXPO_PUBLIC_GOOGLE_MAPS_API_KEY` in `mobile/.env` for local
  development. Restrict production Google keys by application and API, and
  inject them through the build environment. See the Google Maps Platform
  documentation before enabling billing.
- **Push notifications:** Configure EAS project credentials and upload the
  required FCM/APNs credentials through Expo's secure credentials workflow.
  Never check credential files into the repository.
- **Realtime:** Configure `REVERB_*` values in `backend/.env` and run the
  Reverb server. Authorize private booking channels server-side before clients
  subscribe to them.
- **Dashboard:** Set `VITE_API_BASE_URL` in `admin/.env.local`. Vite variables
  are public; do not put secrets in them.
- **Pre-launch site:** Set `VITE_API_BASE_URL` in `prelaunch/.env.local`. The
  site collects separate customer/provider waitlists through `POST
  /api/v1/waitlist`; it does not create marketplace accounts or request
  location/notification permissions.
- **API:** `backend/.env.example` documents the MySQL, Redis cache, Redis queue,
  and CORS settings. `php artisan reverb:install` adds local Reverb credentials
  to `backend/.env`; never commit that file.
- **Payments:** Payment collection is excluded from the MVP. The admin may save
  gateway preferences for Paystack, Flutterwave, or both, but checkout,
  transactions, commissions, refunds, and earnings settlement are not active.
  See [`backend/docs/PAYMENTS.md`](./backend/docs/PAYMENTS.md) for the initial
  provider evaluation, security requirements, and activation boundary.

## Customer mobile experience

The Expo app includes customer onboarding and authentication, nearby service
discovery, search and categories, service and provider profiles, booking
requests, map-based location selection, booking status/history, cancellation,
completion and reviews, saved locations, notifications, account settings, and
support. Customer sessions are stored with Expo SecureStore. Set
`EXPO_PUBLIC_API_BASE_URL` and `EXPO_PUBLIC_GOOGLE_MAPS_API_KEY` in
`mobile/.env` for local development.

The Laravel API exposes the related customer routes under `/api/v1/customer`:

- Public discovery: `GET /categories`, `/services`, `/services/{service}`, and
  `/providers/{provider}`.
- Authenticated customer workflows: bookings, saved locations, notifications,
  push-token registration, profile updates, and support tickets. These routes
  require a customer role and an active account.
- Service search accepts `search`, `category_id`, `latitude`, `longitude`, and
  `radius_km`. Search matches service names/descriptions, active category names
  and descriptions, and provider names. When coordinates are provided, results
  are restricted to providers with mapped coordinates within the requested
  radius and include distance. Service results include provider verification,
  review average/count, and listed pricing; the mobile app provides
  autocomplete from live category and service data and client-side relevance,
  distance, rating, and price sorting.
- Booking requests can omit `scheduled_at` for ASAP service, or specify a
  future timestamp for a scheduled request.

Customer tracking screens reflect booking state returned by the API. Automatic
provider matching ranks verified, active providers with a published service in
the requested service category, current availability, a mapped location, and a
coverage radius that includes the request. Offers go to one provider at a time;
the booking is assigned only after that provider accepts. Rejected and expired
offers advance to the next eligible provider. Customers can retry when a search
is exhausted or cancel while matching. The mobile app polls booking state for
progress and confirmed assignment.

Providers can set current availability, coordinates, and coverage through
`PATCH /api/v1/provider/availability`; current availability defaults to off.
Eligible providers retrieve offers from `GET /api/v1/provider/offers` and
accept or reject with `POST /api/v1/provider/offers/{offer}/respond` and
`{"decision":"accept"}` or `{"decision":"reject"}`. Only providers with
verified email, administrator approval, submitted identity, active services,
current availability, a mapped location, and matching coverage receive offers.
The app's provider dashboard refreshes offers, bookings, earnings and account
notifications. Incoming requests are saved as database notifications and sent
through Expo Push when a provider device has registered a token. Configure Expo
and EAS credentials as described in the Push notifications section above.

The provider app experience is part of `mobile/`: providers can choose a
provider account during registration, verify their email, create a business
profile, add services and pricing, set coverage, upload a profile photo and
portfolio, submit a private identity document, manage availability, respond to
requests, update bookings, view earnings/reviews/notifications, and sign out.
Provider routes use the `/api/v1/provider` prefix. Pending providers may manage
their onboarding details and services but cannot go online or receive offers.
The approval submission endpoint is `POST /provider/submit-for-approval`.
Administrators review submitted ID files through the protected
`GET /api/v1/admin/providers/{user}/identity-document` endpoint. A replacement
identity document returns an already-approved provider to pending status and
turns availability off until reviewed again.

Provider profile photos and portfolio examples use the public filesystem disk.
Run `php artisan storage:link` for the local public disk. Identity documents are
stored on a separate private disk and are never exposed in provider profile
responses. The default is Laravel's private `local` disk; production deployments
should set `NEYBOR_IDENTITY_DISK=s3` and configure the private S3 disk in
`backend/.env`/the deployment secret manager. Never use a public disk for ID
documents. Keep `expo-image-picker` installed through `npx expo install` for
photo and identity uploads.

A queue worker processes matching and Expo push notifications. The Laravel
scheduler must run `bookings:expire-provider-offers` every minute to expire
unanswered offers and continue the search. Run `php artisan schedule:work`
locally; configure the Laravel scheduler in production alongside the Redis
queue worker.

Matching defaults are configured in `backend/config/neybor.php` and can be
overridden with `NEYBOR_MATCH_OFFER_TTL_SECONDS`,
`NEYBOR_MATCH_MAX_DISTANCE_KM`, `NEYBOR_MATCH_DISTANCE_WEIGHT`,
`NEYBOR_MATCH_RATING_WEIGHT`, `NEYBOR_MATCH_EXPERIENCE_WEIGHT`, and
`NEYBOR_MATCH_EXPERIENCE_REVIEW_CAP` in the backend environment. Ranking uses
normalized distance, rating, and review-count experience; eligibility requires
same-category service compatibility and provider-declared coverage.

## Roles and account controls

The API assigns each account exactly one server-controlled role:

| Role | API access |
| --- | --- |
| `customer` | Customer account, marketplace booking, location, notification, and support endpoints. |
| `service_provider` | Provider account/onboarding endpoints; provider operations require verified status. |
| `administrator` | User and provider management plus the administrative audit log. |

Roles cannot be changed through normal user mass assignment. Registration
supports customer and service-provider accounts; administrator role assignment
is restricted to the seeder and protected administrator operations. An
administrator can change an account between customer and service provider, but
the API cannot promote an account to administrator or modify an existing
administrator. Provision the first administrator through the
dedicated seeder, using `ADMIN_NAME`, `ADMIN_EMAIL`, and an
`ADMIN_PASSWORD` of at least 16 characters supplied through a secure local
environment or secret manager:

```powershell
Set-Location backend
php artisan db:seed --class=AdminUserSeeder
```

The seeder refuses to elevate an existing non-administrator account. Do not put
the bootstrap password in source control, shell history, or deployment logs.

All authenticated API routes check account suspension. Suspended accounts
receive `403 account_suspended`, and suspension revokes the account's existing
Sanctum personal access tokens. Role-specific routes also require the matching
server-side role. Providers in pending or rejected verification status can
access provider onboarding and service management but cannot enable availability
or receive customer requests. New providers start in `pending`; approval
requires verified email, phone contact, complete professional profile, mapped
service coverage, identity document, and an active service. Administrators can
approve or reject submitted providers, and rejection requires a reason.

Administrator endpoints:

- `GET /api/v1/admin/overview`, `/users`, `/providers`, `/services`, `/bookings`,
  `/categories`, `/reviews`, `/notifications`, `/reports`, `/settings`,
  `/waitlist`, and `/audit-logs`
- `PATCH /api/v1/admin/users/{user}/role` (customer or service provider only)
- `POST /api/v1/admin/users/{user}/suspend` (`reason` required)
- `POST /api/v1/admin/users/{user}/unsuspend` (`reason` required)
- `POST /api/v1/admin/providers/{user}/verification` (`decision`: `verified`
  or `rejected`; reason required for either decision)
- Service moderation and category/review changes also require reasons and create
  immutable audit entries. All admin API routes require an active administrator
  token; public signup cannot create administrator accounts.

The dashboard contains live API analytics and operational views; unavailable
MVP workflows such as disputes and payments are explicitly marked inactive.
The pre-launch site is a separate responsive public website with customer and
provider waitlists, server validation, duplicate prevention, loading, success,
and error states. It is not the mobile marketplace.

Administrator accounts cannot be suspended or changed through these endpoints,
and administrators cannot change their own roles. Successful account and
verification changes are recorded with actor/target IDs and roles, action,
request metadata, and the change context. Audit entries are append-only through
the application and readable only by administrators. Keep access to production
logs and database credentials restricted, and set a retention policy for
request metadata.

Provider booking operations validate status transitions and only act on bookings
assigned to the authenticated provider. Provider service and portfolio routes
are scoped to the current account; identity files are only streamed through the
administrator review route.
