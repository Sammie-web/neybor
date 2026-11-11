# Neybor API

See the complete [API reference](./docs/API.md) for all routes, payloads, response formats, authorization rules, realtime channels, and delivery behavior. The [data model](./docs/DATA_MODEL.md) documents the implemented schema, constraints, indexes, ER diagram, and deliberate scope boundaries.

The versioned API is rooted at `/api/v1`. Send and receive JSON. Protected endpoints accept a Sanctum personal access token in `Authorization: Bearer <token>`. Errors use standard Laravel JSON validation responses (HTTP 422); unauthorized and forbidden requests return 401 and 403.

## Customer authentication

- `POST /auth/register` — `{name, email, password, password_confirmation, phone?, role?}`; creates a customer by default or a service provider when requested. Administrator self-registration is never granted.
- `POST /auth/login` — `{email, password}`; returns `{data, token}`.
- `POST /auth/logout`, `GET /auth/me` — authenticated.
- `POST /auth/email/verification-notification` — authenticated resend.
- Email verification uses the signed link delivered by email. `GET /auth/email/verify/{id}/{hash}` accepts that link; `POST /auth/email/verify` accepts either signed `id`, `hash`, `expires`, and `signature` query parameters or `{verification_url: "<signed link>"}`.
- `POST /auth/forgot-password` — `{email}`; returns a generic response and emails the reset token.
- `POST /auth/reset-password` — `{email, token, password, password_confirmation}`.

Mail transport must be configured for verification and password-reset emails. Run `php artisan migrate` after configuring the application database. Sanctum bearer tokens are issued at registration/login and revoked at logout/password reset.

## Discovery

Public `GET /customer/categories`, `GET /customer/services`, `GET /customer/services/{service}`, and `GET /customer/providers/{provider}` return only active services from verified, active providers. Service search accepts `search`, `category_id`, `latitude`, `longitude`, and `radius_km` (default 25 km when coordinates are supplied). Search results include service, category, provider summary, and—when a location is given—`distance_km`. Provider and service detail responses include public review summaries.

## Customer endpoints

All endpoints below require an authenticated, active customer.

- `GET|POST /customer/bookings`; `GET /customer/bookings/{booking}`; `POST /customer/bookings/{booking}/retry-matching`.
- `POST /customer/bookings/{booking}/cancel|complete|review`.
- Booking creation accepts `{service_id, address, latitude, longitude, scheduled_at, notes?}` and starts in `pending_matching` with no assigned provider. Only the assigned provider can mark a booking complete. Review accepts `{rating: 1..5, comment?}` and only completed bookings can be reviewed once.
- `GET|POST /customer/saved-locations`; `PATCH|DELETE /customer/saved-locations/{location}`. Location fields are `{label, address, latitude, longitude, is_default?}`.
- `GET /customer/notifications`; `POST /customer/notifications/{id}/read`.
- `POST /customer/push-tokens` — `{token, platform: "ios"|"android", device_id?}`.
- `PATCH /customer/profile` — `{name?, phone?}`.
- `GET|POST /customer/support-tickets`; creation accepts `{subject, message}`.

Bookings, saved locations, notification records, support tickets, and push tokens are always scoped to the authenticated customer. Provider and administrator routes are separate and unchanged.

## Provider job workflow

Provider accounts must be active, email-verified, identity-submitted, approved, online, and have an active service to receive requests. Matching filters by service compatibility, location, provider coverage, verification, availability, and absence of another active job. Service compatibility defaults to another active service in the requested category; set `NEYBOR_MATCH_SERVICE_COMPATIBILITY=exact` to require the exact selected service. Eligible candidates are scored with configurable distance, service-name relevance, rating, and availability-recency weights. Providers without reviews receive a neutral rating score. `available_since` is refreshed when a provider comes online; recency is capped by `NEYBOR_MATCH_AVAILABILITY_FRESHNESS_MINUTES`. Weights are normalized and should be non-negative.

The default offer policy is sequential (`NEYBOR_MATCH_OFFER_MODE=sequential`). Set it to `parallel` to offer concurrently to up to `NEYBOR_MATCH_OFFER_BATCH_SIZE` candidates (maximum 10). Responses and expiry fill available offer slots without duplicating providers in one attempt. `NEYBOR_MATCH_OFFER_TTL_SECONDS`, `NEYBOR_MATCH_MAX_DISTANCE_KM`, and `NEYBOR_MATCH_MAX_ATTEMPTS` tune expiration, the global distance cap, and customer retry limits; a zero retry limit means unlimited attempts. Provider rejections and expired offers advance matching; no eligible providers produce `no_provider_available`. Each dispatch round is recorded in `booking_matching_attempts`, while individual offers retain their provider, score, outcome, and attempt number. Offer previews show approximate distance, not the customer's name, phone number, exact address, or coordinates.

- `GET /provider/offers`; `POST /provider/offers/{offer}/respond` with `{decision: "accept"|"reject"}`.
- `PATCH /provider/bookings/{booking}` advances one step at a time: `matched` → `accepted` → `en_route` → `arrived` → `in_progress` → `completed`. Completion requires a non-negative `quote_amount`.
- `POST /provider/bookings/{booking}/cancel` requires a reason of 10–1000 characters and is allowed only before arrival. The booking returns to matching and excludes the cancelling provider for that booking.
- `pending_matching` represents system searching; `offered` means at least one eligible provider has an active offer; `matched` means a provider accepted an offer and the assignment is confirmed; `no_provider_available` means the current attempt exhausted eligible candidates. Offer `rejected` and `expired` outcomes are recorded on the offer rather than as terminal booking states.
- Permitted booking transitions and actors:

  | From | To | Authorized actor |
  | --- | --- | --- |
  | `pending_matching` | `offered`, `no_provider_available` | Matcher/queue |
  | `pending_matching`, `offered` | `cancelled` | Owning customer |
  | `offered` | `matched` | Assigned provider accepting the offer |
  | `matched` | `accepted` | Assigned provider |
  | `matched`, `accepted`, `en_route` | `pending_matching` | Assigned provider cancellation (reason required) |
  | `accepted` | `en_route` | Assigned provider |
  | `en_route` | `arrived` | Assigned provider |
  | `arrived` | `in_progress` | Assigned provider |
  | `in_progress` | `completed` | Assigned provider |
  | `no_provider_available` | `pending_matching`, `cancelled` | Owning customer (retry is attempt-limited) |

  `completed` and `cancelled` are terminal. Customers cannot mark work complete, and providers cannot skip job-progress steps or cancel after arrival.
- Booking transitions, provider offer acceptance, and provider availability changes are transactional. The booking row serializes competing decisions for one request; the provider profile row serializes acceptances across different requests to prevent double assignment. Job status changes are recorded in `booking_status_events`; customers receive progress notifications. Provider availability is restored after completion or cancellation only when the provider was available before assignment and remains eligible.

## Realtime and queue workers

The local `.env.example` uses `BROADCAST_CONNECTION=log`. For WebSocket updates, configure Laravel Reverb credentials and `BROADCAST_CONNECTION=reverb`, then run `php artisan reverb:start`. Run a Redis queue worker (`php artisan queue:work redis`) for matching and notification jobs. Booking status is authoritative in REST/database state; clients must refetch after reconnecting and deduplicate push notifications using `notification_id`. See the [API realtime section](./docs/API.md#real-time-and-delivery) for channels and delivery guarantees.
