# Neybor data model

The schema is implemented by `database/migrations`. MySQL is the production database; tests use SQLite. The ER diagram below covers application-domain tables only. Framework tables (`cache`, `jobs`, `job_batches`, `failed_jobs`, and `personal_access_tokens`) are intentionally omitted from the domain diagram.

```mermaid
erDiagram
    USERS ||--o| PROVIDER_PROFILES : has
    USERS ||--o{ SERVICES : offers
    SERVICE_CATEGORIES ||--o{ SERVICES : categorizes
    USERS ||--o{ BOOKINGS : requests
    USERS o|--o{ BOOKINGS : assigned_provider
    SERVICES ||--o{ BOOKINGS : requested_service
    BOOKINGS ||--o{ BOOKING_STATUS_EVENTS : history
    USERS o|--o{ BOOKING_STATUS_EVENTS : actor
    BOOKINGS ||--o{ BOOKING_MATCHING_ATTEMPTS : matching_rounds
    BOOKINGS ||--o{ PROVIDER_OFFERS : dispatches
    USERS ||--o{ PROVIDER_OFFERS : receives
    SERVICES ||--o{ PROVIDER_OFFERS : offered_service
    BOOKINGS ||--o{ BOOKING_PROVIDER_EXCLUSIONS : excludes
    USERS ||--o{ BOOKING_PROVIDER_EXCLUSIONS : excluded_provider
    BOOKINGS ||--o| REVIEWS : reviewed_by_customer
    USERS ||--o{ REVIEWS : writes
    USERS ||--o{ REVIEWS : receives
    USERS ||--o{ SAVED_LOCATIONS : saves
    USERS ||--o{ SUPPORT_TICKETS : opens
    USERS ||--o{ USER_PUSH_TOKENS : registers
    USERS ||--o{ PROVIDER_PORTFOLIO_ITEMS : displays
    USERS o|--o{ ADMINISTRATIVE_AUDIT_LOGS : administrator_actor
    USERS o|--o{ ADMINISTRATIVE_AUDIT_LOGS : target
    USERS ||--o{ NOTIFICATIONS : notifiable
    PLATFORM_SETTINGS {
        bigint id PK
        string key UK
        json value
    }
    WAITLIST_REGISTRATIONS {
        bigint id PK
        string name
        string email
        string role
        string phone
        string business_name
        string service_interest
        timestamp created_at
    }

    USERS {
        bigint id PK
        string name
        string email UK
        string role
        string provider_verification_status
        timestamp suspended_at
        string phone
    }
    PROVIDER_PROFILES {
        bigint id PK
        bigint user_id FK,UK
        string business_name
        text bio
        json skills
        decimal latitude
        decimal longitude
        decimal coverage_radius_km
        boolean is_available
        timestamp available_since
        string identity_document_path
    }
    SERVICE_CATEGORIES {
        bigint id PK
        string slug UK
        string name
        boolean is_active
    }
    SERVICES {
        bigint id PK
        bigint provider_id FK
        bigint category_id FK
        string name
        decimal base_price
        boolean is_active
    }
    BOOKINGS {
        bigint id PK
        bigint customer_id FK
        bigint provider_id FK
        bigint service_id FK
        string status
        string requested_address
        decimal latitude
        decimal longitude
        timestamp scheduled_at
        unsigned_int matching_attempt
        decimal quote_amount
    }
    BOOKING_STATUS_EVENTS {
        bigint id PK
        bigint booking_id FK
        bigint actor_user_id FK
        string from_status
        string to_status
        text reason
        timestamp created_at
    }
    BOOKING_MATCHING_ATTEMPTS {
        bigint id PK
        bigint booking_id FK
        unsigned_int attempt
        string status
        unsigned_int candidate_count
        unsigned_int offers_dispatched
        text failure_reason
    }
    PROVIDER_OFFERS {
        bigint id PK
        bigint booking_id FK
        bigint provider_id FK
        bigint service_id FK
        unsigned_int matching_attempt
        string status
        decimal score
        decimal distance_km
        timestamp expires_at
    }
    REVIEWS {
        bigint id PK
        bigint booking_id FK,UK
        bigint customer_id FK
        bigint provider_id FK
        unsigned_tinyint rating
    }
    SAVED_LOCATIONS {
        bigint id PK
        bigint user_id FK
        string label
        string address
        decimal latitude
        decimal longitude
        boolean is_default
    }
    BOOKING_PROVIDER_EXCLUSIONS {
        bigint id PK
        bigint booking_id FK
        bigint provider_id FK
        text reason
    }
    PROVIDER_PORTFOLIO_ITEMS {
        bigint id PK
        bigint provider_id FK
        string image_path
        string caption
        unsigned_smallint sort_order
    }
    SUPPORT_TICKETS {
        bigint id PK
        bigint user_id FK
        string subject
        text message
        string status
    }
    USER_PUSH_TOKENS {
        bigint id PK
        bigint user_id FK
        string token UK
        string platform
        string device_id
    }
    ADMINISTRATIVE_AUDIT_LOGS {
        bigint id PK
        bigint actor_user_id FK
        bigint target_user_id FK
        string actor_role
        string target_role
        string action
        json context
        string ip_address
    }
    NOTIFICATIONS {
        uuid id PK
        string type
        string notifiable_type
        bigint notifiable_id
        text data
        timestamp read_at
    }
```

## Tables and constraints

| Table | Purpose and important constraints |
| --- | --- |
| `users` | Authentication identity, email verification, role, provider-approval status, suspension metadata, and phone. Roles and verification states are application enums stored as strings. Administrator role is not self-assignable at registration. |
| `provider_profiles` | One-to-one provider professional, location, coverage, availability, and identity-document fields. Unique `user_id`; location and availability have a composite query index. Identity files use the configured private identity disk. |
| `service_categories` | Catalogue categories; unique slug and active flag. |
| `services` | Provider-owned offers in one category, with description and optional price/unit. Provider and category FKs; category/active index. A provider can publish multiple services. |
| `bookings` | The customer service request and booking lifecycle in one row. Customer and service are required; assigned provider is nullable until offer acceptance and is cleared on provider cancellation/rematching. Address and precise coordinates are booking-private. `scheduled_at` is nullable; matching attempt metadata, availability snapshot, completion timestamp, and final quote are persisted here. |
| `provider_offers` | Individual provider dispatches and responses, including attempt, expiry, score, and distance. Unique `(booking_id, matching_attempt, provider_id)` prevents duplicate offers to a provider in one attempt. |
| `booking_matching_attempts` | Auditable per-request dispatch rounds, counts, result state, and failure reason. Unique `(booking_id, attempt)`. |
| `booking_status_events` | Append-only transition history with optional actor and reason. Indexed by booking and creation time; a new event triggers after-commit realtime and notification handling. |
| `booking_provider_exclusions` | Prevents a provider who cancelled a booking from being offered that same request again. Unique `(booking_id, provider_id)`. |
| `reviews` | One review per booking (`booking_id` unique), with customer, provider, rating, and optional comment. Provider/date index supports provider review summaries. |
| `saved_locations` | User-owned addresses and coordinates; indexed by `(user_id, is_default)`. |
| `provider_portfolio_items` | Provider-owned public portfolio media and display order. |
| `support_tickets` | User-owned support requests with a simple status. |
| `user_push_tokens` | Device/platform registration; token is globally unique to avoid sending duplicate pushes for one installation. |
| `notifications` | Laravel polymorphic in-app notifications with UUID IDs and indexed unread timestamp. Stable notification IDs make database delivery idempotent on queue retries. |
| `administrative_audit_logs` | Administrative action, actor/target snapshots, context, IP, and user agent. Indexed by action/date and target/date; records are not updated by application workflows. |
| `platform_settings` | One JSON document per unique setting key. `marketplace` stores non-secret pricing/gateway preferences, blank policy values, and an invariant disabled-payment flag. Gateway selections are not connected to checkout. |
| `waitlist_registrations` | Pre-launch website registrations, with customer/provider audience, normalized email, optional phone and service-interest fields. Unique `(email, role)` prevents duplicate signups while permitting one customer and one provider entry for the same address. No user account is created. |

Core foreign keys cascade when an owning account/request is deleted, except bookings retain referential integrity to the selected service/category (service category deletion is restricted, and service deletion is restricted while referenced); optional provider/actor references are nullable where the workflow requires it. Database-level uniqueness guards one review per booking, one provider profile per user, one offer per provider per attempt, and one exclusion per booking/provider. Booking state transitions and matching acceptance also use transactions and row locks.

## Query and location design

Provider and customer coordinates use `DECIMAL(10,7)` and coverage uses a radius in kilometers. The matcher narrows candidates with an indexed availability/latitude/longitude bounding box, then applies exact Haversine distance and provider coverage checks. It does not depend on MySQL GIS extensions. The booking/service/provider status and offer-expiry indexes support active-job lookup and queue dispatch. If catalogue scale or geographic density grows significantly, evaluate a spatial index or dedicated geospatial search after measuring the current query plan.

## Deliberate scope boundaries

The current release has no standalone `service_requests` table: `bookings` represents a customer request from initial matching through completion. Availability is current provider state on `provider_profiles`; there is no weekly schedule or time-slot calendar table yet. Provider-service association is represented directly by `services.provider_id`, not a redundant `provider_services` join table. Service coverage is represented by a provider center and radius, not polygons or a separate service-area table.

There are no payment/transaction, conversation/message, dispute, or verification-submission tables in the shipped schema. Identity-document metadata is on `provider_profiles`; the actual file is stored on the configured private storage disk. These features require their own requirements, retention policy, and access controls before schema/API work begins.

The admin payment preferences are not a payment ledger. Pricing selection, gateway selection, commission/cancellation/refund values are configuration records only; marketplace checkout and payout logic do not consume them. Payment collection is disabled until provider integrations and the relevant product rules have been implemented and tested.

## Migrations and sample data

Configure `.env`, then run `php artisan migrate`. `DatabaseSeeder` invokes `DemoMarketplaceSeeder` only in local environments; the demo seeder refuses to run in production. It creates sample catalogue/provider data with a placeholder identity-document path and no usable demo password. Do not treat the sample provider as a verified real person or use the placeholder as a verification decision.
