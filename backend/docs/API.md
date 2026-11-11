# Neybor REST API v1

Base URL: `/api/v1`. Requests and responses use JSON unless an endpoint accepts a multipart upload or returns a file. Use `Authorization: Bearer <sanctum-token>` for authenticated routes. All authenticated endpoints reject suspended accounts. Roles are `customer`, `service_provider`, and `administrator`.

## Conventions

- Successful single-resource responses use `{ "data": <resource> }`; action responses may also include `message`. Paginated collections use Laravel's `{ "data": [], "links": {}, "meta": {} }` format and accept `page` and `per_page` where stated.
- Errors use `{ "message": "...", "code": "...", "errors": {} }`. `errors` is present for validation failures (`422`, `validation_error`). Common statuses are `401 unauthenticated`, `403 forbidden`, `404 not_found`, and `409 conflict`.
- Form validation is server-side. Coordinates are WGS84 decimal degrees; latitude is `-90..90`, longitude `-180..180`.
- Uploads use `multipart/form-data`. Resources are owner-scoped; another user's booking, saved location, notification, service, or portfolio item is not exposed.

## Health and authentication

| Method and path | Authorization | Request | Response |
| --- | --- | --- | --- |
| `GET /health` | Public; throttled | None | `{status, service, timestamp}` |
| `POST /auth/register` | Public; 10/minute | `{name, email, password, password_confirmation, phone? , role?}`. Name max 255; email unique; password min 8 and confirmed; role customer or service_provider. An administrator role request is deliberately downgraded to customer. | `201 {data: {id, name, email, phone, email_verified, role, provider_verification_status}, token}` |
| `POST /auth/login` | Public; 10/minute | `{email, password}` | `{data: user, token}`. Suspended accounts return 403. |
| `POST /auth/logout` | Authenticated active user | None | `{message}`; revokes current Sanctum token. |
| `GET /auth/me` | Authenticated active user | None | `{data: user}` |
| `POST /auth/email/verification-notification` | Authenticated active user; 6/minute | None | `{message}`; sends only if the email is not verified. |
| `POST /auth/email/verify` | Authenticated active user | Signed `id`, `hash`, `expires`, `signature` query, or `{verification_url: "<signed URL>"}` | `{message, data: user}` |
| `GET /auth/email/verify/{id}/{hash}` | Public, signed and expiring URL | Signed query string | `{message, data: user}` |
| `POST /auth/forgot-password` | Public; 5/minute | `{email}` | Generic `{message}` whether or not an eligible account exists. |
| `POST /auth/reset-password` | Public; 5/minute | `{email, token, password, password_confirmation}` | `{message}`; revokes existing tokens. |

Provider accounts require administrator verification for offer, availability, and provider job endpoints. Email verification and active-account checks are enforced server-side.

`GET /user` is a legacy authenticated endpoint that returns the current user model for any active role; new clients should prefer `GET /auth/me`, which returns the documented safe user shape.

## Discovery

| Method and path | Authorization | Request | Response |
| --- | --- | --- | --- |
| `GET /customer/categories` | Public | None | `{data: [{id, name, slug, description, icon_url, ...}]}` |
| `GET /customer/services` | Public | Query: `search?` (max 120), `category_id?`, `latitude?`, `longitude?`, `radius_km?` (1..200; defaults to 25 km when coordinates are supplied), `page?`, `per_page?` (1..50; default 20). Coordinates must be supplied together. | Paginated `{data: [{id, name, description, base_price, price_unit, category, provider, distance_km?}], links, meta}`. Results are limited to the first 500 matching catalogue rows before distance pagination. |
| `GET /customer/services/{service}` | Public | Active service ID | `{data: service summary with provider and review summary}`; undiscoverable services return 404. |
| `GET /customer/providers/{provider}` | Public | Verified provider user ID | `{data: provider summary, services: [], reviews: {data, links, meta}, rating_average, rating_count}` |

Discovery only returns active services in active categories belonging to verified, email-verified, unsuspended providers. Provider coordinates in public summaries are approximate profile coordinates; request addresses are private.

## Customer account, booking, and support

All routes in this section require an active customer token, except the public discovery routes above.

| Method and path | Request | Response |
| --- | --- | --- |
| `GET /customer/account` | None | `{data: authenticated customer}` |
| `PATCH /customer/profile` | `{name?, phone?}`; name max 255, phone max 32 | `{data: {id, name, email, phone}}` |
| `GET /customer/bookings` | Query: `page?`, `per_page?` (15 default) | Paginated `BookingResource` records |
| `POST /customer/bookings` | `{service_id, address, latitude, longitude, scheduled_at?, notes?}`; service must be active; address max 500, notes max 2000; scheduled date must be in the future | `201 {data: BookingResource}`. Creates a request with no assigned provider and starts matching after commit. |
| `GET /customer/bookings/{booking}` | Owned booking ID | `{data: BookingResource}` including matching summary and status history |
| `POST /customer/bookings/{booking}/retry-matching` | Owned `no_provider_available` booking; no body | `{data: BookingResource}`. Increments matching attempt unless `NEYBOR_MATCH_MAX_ATTEMPTS` is exhausted; zero means unlimited. |
| `POST /customer/bookings/{booking}/cancel` | Owned cancellable booking; no body | `{data: BookingResource}`. Allowed before arrival, including while matching or an offer is outstanding. |
| `POST /customer/bookings/{booking}/complete` | Owned booking; no body | Always `409 conflict`; completion is provider-authoritative. |
| `POST /customer/bookings/{booking}/review` | `{rating, comment?}`; rating integer 1..5, comment max 2000 | `201 {data: review}`. Only the owner may review a completed booking, once. |
| `GET /customer/saved-locations` | None | `{data: saved locations}` |
| `POST /customer/saved-locations` | `{label, address, latitude, longitude, is_default?}`; label max 80, address max 500 | `201 {data: saved location}`; first location becomes default. |
| `PATCH /customer/saved-locations/{location}` | Any subset of `{label, address, latitude, longitude, is_default}` with the same field rules | `{data: saved location}` |
| `DELETE /customer/saved-locations/{location}` | None | `204`; deleting the default promotes another location when available. |
| `GET /customer/notifications` | Query: `page?` | Paginated in-app notifications; `read_at` indicates unread/read state. |
| `POST /customer/notifications/{notification}/read` | Owned notification; no body | `{data: notification}` with `read_at` set. |
| `POST /customer/push-tokens` | `{token, platform: "ios"|"android", device_id?}`; token max 512 | `201 {data: token}` or `200` when the token is already registered to this user. |
| `GET /customer/support-tickets` | Query: `page?` | Paginated support tickets (15 per page). |
| `POST /customer/support-tickets` | `{subject, message}`; subject max 160, message max 5000 | `201 {data: ticket}` |

`BookingResource` exposes customer address/location to the owning customer. Provider contact details are returned only to the assigned provider after assignment; offer previews do not include customer identity, phone, exact address, or coordinates.

## Provider profile, service catalogue, availability, and jobs

All routes require an active `service_provider` token. Routes marked **verified** additionally require verified email, identity submission, and administrator approval.

| Method and path | Authorization | Request | Response |
| --- | --- | --- | --- |
| `GET /provider/account` | Provider | None | `{data: user, capabilities: {manage_services}}` |
| `GET /provider/dashboard` | Provider | None | `{data: {verification_status, email_verified, profile, stats, offers, bookings, notifications, reviews}}` |
| `GET /provider/profile` | Provider | None | `{data: provider profile}` |
| `PATCH /provider/profile` | Provider | Any subset of `{name, phone, business_name, bio, skills[], latitude, longitude, coverage_radius_km}`; documented validation is enforced by controller (skills max 30; coverage 1..200 km). | `{data: provider profile}` |
| `POST /provider/profile/photo` | Provider, multipart | `photo`: JPG/JPEG/PNG/WEBP image, max 5 MB | `{data: {avatar_url}}` |
| `POST /provider/identity-document` | Provider, multipart | `document`: JPG/JPEG/PNG/PDF, max 8 MB; `document_type`: `national_id`, `drivers_license`, `voters_card`, or `passport` | `201 {data: {identity_document_type, identity_submitted_at}}` |
| `POST /provider/submit-for-approval` | Provider | None | `{message, data: {verification_status}}`; profile completeness is required. |
| `GET /provider/services` | Provider | None | `{data: owned services with categories}` |
| `POST /provider/services` | Provider | `{category_id, name, description, base_price?, price_unit?, is_active?}`; active category; name 3..160; description 10..3000; non-negative price | `201 {data: service}` |
| `PATCH /provider/services/{service}` | Owner provider | Any subset of service creation fields | `{data: service}` |
| `DELETE /provider/services/{service}` | Owner provider | None | `{message}`; deactivates; active bookings prevent deactivation. |
| `GET /provider/portfolio` | Provider | None | `{data: portfolio items}` |
| `POST /provider/portfolio` | Provider, multipart | `image`: JPG/JPEG/PNG/WEBP, max 8 MB; `caption?` max 160 | `201 {data: portfolio item}` |
| `DELETE /provider/portfolio/{item}` | Owner provider | None | `{message}` |
| `GET /provider/categories` | Provider | None | Same active-category list as `GET /customer/categories`. |
| `PATCH /provider/availability` | **Verified** | `{is_available, latitude?, longitude?, coverage_radius_km?}`; coordinates required when going online | `{data: {is_available, latitude, longitude, coverage_radius_km}}`; only eligible providers with no active booking can go online. |
| `GET /provider/operations` | **Verified** | None | `{role, status}` |
| `GET /provider/offers` | **Verified** | Query: `page?` | Paginated, unexpired offers; preview has service, scheduled time, notes, approximate distance, and a generic customer label. |
| `POST /provider/offers/{offer}/respond` | **Verified**, offer owner | `{decision: "accept"|"reject"}` | Acceptance: `{message, data: BookingResource}`. Reject/expiry: `{message}` and matching continues. |
| `GET /provider/bookings` | Provider | Query: `status=active|completed`, `page?` (20 per page) | Paginated provider-owned bookings with customer summary, service/category, and status history. Customer phone is only present on assigned bookings. |
| `PATCH /provider/bookings/{booking}` | Assigned provider | `{status}` where status is the next permitted state; completion additionally requires `quote_amount` >= 0 | `{data: BookingResource}` |
| `POST /provider/bookings/{booking}/cancel` | Assigned provider before arrival | `{reason}`; 10..1000 characters | `{data: BookingResource}`; re-matches and excludes this provider from the same booking. |
| `GET /provider/notifications` | Provider | Query: `page?` | Paginated in-app notifications. |
| `POST /provider/notifications/{notification}/read` | Provider, notification owner | None | `{message}` |
| `POST /provider/push-tokens` | Provider | Same body as customer push-token registration | Same response as customer registration. |

## Administrator

All routes require an active `administrator` token.

| Method and path | Request | Response |
| --- | --- | --- |
| `GET /admin/users` | Query: `role?`, `suspended?`, `per_page?` (1..100, default 25), `page?` | Paginated user summaries. |
| `PATCH /admin/users/{user}/role` | `{role: "customer"|"service_provider"}` | `{data: user summary}`; cannot change administrator roles or own role. |
| `POST /admin/users/{user}/suspend` | `{reason}`; 3..1000 characters | `{data: user summary}`; revokes tokens and takes providers offline. |
| `POST /admin/users/{user}/unsuspend` | `{reason}`; 3..1000 characters | `{data: user summary}`; does not automatically restore provider availability. |
| `GET /admin/providers` | Query: `search?`, `status?`, `suspended?`, `page?` | Paginated provider profiles and active service counts. |
| `POST /admin/providers/{user}/verification` | `{decision: "verified"|"rejected", reason}`; reason always required | `{data: {id, role, provider_verification_status}}`; approval requires verified email, phone, profile, identity submission, location, and active service. |
| `GET /admin/providers/{user}/identity-document` | Administrator; provider identity is audited | Private document download; missing document returns 404. |
| `GET /admin/overview` | None | Live counts, booking states/activity, waitlist count, recent audit records. |
| `GET /admin/services` | Query: `search?`, `active?`, `page?`, `per_page?` | Paginated service/provider/category records. |
| `PATCH /admin/services/{service}/moderation` | `{is_active, reason}`; reason 3..1000 | `{data: service}`; audit-logged; providers with no remaining active service are taken offline. |
| `GET /admin/bookings` | Query: `search?`, `status?`, `page?`, `per_page?` | Paginated booking monitoring data. |
| `GET /admin/categories` | None | `{data: categories with services_count}` |
| `POST /admin/categories` | `{name, slug?, description?, icon_url?, is_active?}` | `201 {data: category}`; slug is generated from name if omitted. |
| `PATCH /admin/categories/{category}` | Any category fields plus required `{reason}` | `{data: category}`; updates are audited. |
| `DELETE /admin/categories/{category}` | `{reason}` | `{message}`; category must have no services; deletion is audited. |
| `GET /admin/reviews` | Query: `search?`, `rating?`, `page?`, `per_page?` | Paginated review/customer/provider/service details. |
| `DELETE /admin/reviews/{review}` | `{reason}` | `{message}`; removal and reason are audited. |
| `GET /admin/notifications` | None | Paginated notifications for the authenticated administrator only. |
| `POST /admin/notifications/{notification}/read` | Own notification | `{data: notification}` |
| `GET /admin/reports` | None | Booking-by-month, provider verification, waitlist and review aggregates; payment revenue/transactions are explicitly `null` and `not_implemented`. |
| `GET /admin/settings` | None | Saved pricing/gateway preferences, blank business policies, and `payments_enabled: false`. |
| `PATCH /admin/settings` | `{pricing_model?, payment_gateways?, commission_rate?, cancellation_fee?, refund_policy?}` | `{data, message}`; settings and changed field names are audited; gateway selection does not activate payment processing. |
| `GET /admin/waitlist` | Query: `role?`, `search?`, `page?` | Paginated public-site waitlist registrations. |
| `GET /admin/audit-logs` | Query: `page?` | Paginated immutable administrative audit records (50 per page). |

All `/admin/*` API routes require a Sanctum administrator role, an active account, and a per-minute rate limit. Suspension, reactivation, verification decisions, service moderation, category changes, and review removal require a reason and create audit entries. Public registration never grants an administrator role; provision administrators through the protected bootstrap process.

`POST /waitlist` is public and rate-limited to 5 requests per minute. It accepts `{name, email, role: "customer"|"provider", phone?, business_name?, service_interest?}`. Provider entries require `business_name`. Email is normalized to lowercase and unique per audience; duplicates return HTTP 200 with `code: "already_registered"`. New entries return `201`. This endpoint creates a waitlist record, not a user account.

Disputes, transactions, payments, provider earnings ledger, refunds, and checkout are not implemented. See [payment evaluation and boundary](./PAYMENTS.md); gateway preference is nonfunctional intent only while payments remain disabled.

## Real-time and delivery

Booking state and `booking_status_history` are authoritative; WebSocket messages are invalidation/update hints. After reconnecting, clients fetch the booking resource and use its latest status-history event ID to recover missed updates.

- Private channel `bookings.{bookingId}`: only the customer and assigned provider may subscribe. Event name `booking.status.changed`; payload `{event_id, booking_id, from_status, status, occurred_at}`. No address, phone, or matching score is broadcast.
- Private channel `App.Models.User.{userId}`: the same user may subscribe. New provider offers are broadcast as Laravel notifications on this channel.
- Events are dispatched after the status-history transaction commits. Configure `BROADCAST_CONNECTION=reverb`, Reverb application credentials, and start `php artisan reverb:start`. `log` is the development fallback, not a WebSocket transport.
- Notifications use a queued listener, database unread/read state, Expo push for registered devices, and stable `notification_id` values. In-app database writes are idempotent. Push delivery is at-least-once: clients should deduplicate by `notification_id`; failed Expo tickets/network errors are retried by the queue. Run a Redis queue worker and configure the Expo push channel.
- Poll the REST booking endpoint after reconnect, on foreground/resume, or when a broadcast is missed. Do not treat push or broadcast delivery as a booking-state mutation.

## Not currently exposed

There are no payment, transaction, dispute, customer-provider messaging, conversation, scheduled-availability, or historical verification-submission endpoints in this release. These capabilities are intentionally not represented by placeholder tables. Add their API and data model only with the corresponding product/payment/security requirements.
