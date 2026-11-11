<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ProviderOfferStatus;
use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\ProviderOffer;
use App\Models\ProviderProfile;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\BookingMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_always_creates_a_customer_and_requires_auth_for_customer_data(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mobile Customer',
            'email' => 'mobile@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => UserRole::Administrator->value,
        ])->assertCreated()
            ->assertJsonPath('data.role', UserRole::Customer->value)
            ->assertJsonStructure(['data' => ['id', 'email', 'role'], 'token']);

        $this->getJson('/api/v1/customer/bookings')->assertUnauthorized();
        $provider = $this->userWithRole(UserRole::ServiceProvider);
        $this->actingAs($provider, 'sanctum')->getJson('/api/v1/customer/bookings')->assertForbidden();
    }

    public function test_customer_can_login_read_their_profile_and_revoke_their_token(): void
    {
        $customer = User::factory()->create(['email' => 'login@example.test']);
        $customer->forceFill(['role' => UserRole::Customer])->save();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.id', $customer->id);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_booking_creation_is_validated_private_and_starts_unmatched(): void
    {
        [$provider, $service] = $this->availableService();
        $customer = $this->userWithRole(UserRole::Customer);
        $otherCustomer = $this->userWithRole(UserRole::Customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/customer/bookings', ['service_id' => $service->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address', 'latitude', 'longitude']);

        $response = $this->postJson('/api/v1/customer/bookings', [
            'service_id' => $service->id,
            'address' => '20 Main Street',
            'latitude' => 40.1,
            'longitude' => -73.9,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'notes' => 'Call on arrival',
        ])->assertCreated()
            ->assertJsonPath('data.status', BookingStatus::NoProviderAvailable->value)
            ->assertJsonPath('data.provider_id', null)
            ->assertJsonPath('data.requested_address', '20 Main Street');

        $bookingId = $response->json('data.id');
        $this->getJson("/api/v1/customer/bookings/{$bookingId}")->assertOk();
        $this->actingAs($otherCustomer, 'sanctum')
            ->getJson("/api/v1/customer/bookings/{$bookingId}")
            ->assertNotFound();
        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'customer_id' => $customer->id,
            'provider_id' => null,
            'latitude' => 40.1,
            'longitude' => -73.9,
        ]);
        $this->assertDatabaseHas('booking_matching_attempts', [
            'booking_id' => $bookingId,
            'attempt' => 1,
            'status' => 'no_match',
        ]);

        $this->postJson('/api/v1/customer/bookings', [
            'service_id' => $service->id,
            'address' => '20 Main Street',
            'latitude' => 6.335,
            'longitude' => 5.6037,
        ])->assertCreated()
            ->assertJsonPath('data.scheduled_at', null);

        $this->assertSame(UserRole::ServiceProvider, $provider->role);
    }

    public function test_service_search_matches_category_and_returns_nearby_provider_details(): void
    {
        [$provider, $service] = $this->availableService();
        $customer = $this->userWithRole(UserRole::Customer);
        $booking = Booking::query()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'provider_id' => $provider->id,
            'status' => BookingStatus::Completed,
            'requested_address' => '20 Main Street',
            'scheduled_at' => now()->addDay(),
        ]);
        Review::query()->create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'provider_id' => $provider->id,
            'rating' => 5,
            'comment' => 'Excellent work',
        ]);

        $this->getJson('/api/v1/customer/services?search=cleaning')
            ->assertOk()
            ->assertJsonPath('data.0.id', $service->id)
            ->assertJsonPath('data.0.category.name', 'Cleaning')
            ->assertJsonPath('data.0.provider.name', $provider->name)
            ->assertJsonPath('data.0.provider.is_verified', true)
            ->assertJsonPath('data.0.provider.rating_average', 5)
            ->assertJsonPath('data.0.provider.rating_count', 1);

        $this->getJson('/api/v1/customer/services?search=window&latitude=40&longitude=-74&radius_km=5')
            ->assertOk()
            ->assertJsonPath('data.0.distance_km', 0);

        $this->getJson('/api/v1/customer/services?search=plumber')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_public_provider_locations_are_rounded_to_protect_exact_addresses(): void
    {
        [$provider] = $this->availableService();
        $provider->providerProfile()->update([
            'latitude' => 40.1234,
            'longitude' => -74.9876,
        ]);

        $this->getJson("/api/v1/customer/providers/{$provider->id}")
            ->assertOk()
            ->assertJsonPath('data.latitude', 40.123)
            ->assertJsonPath('data.longitude', -74.988);
    }

    public function test_booking_transitions_and_review_require_the_correct_owner_and_status(): void
    {
        [$provider, $service] = $this->availableService();
        $customer = $this->userWithRole(UserRole::Customer);
        $otherCustomer = $this->userWithRole(UserRole::Customer);
        $booking = Booking::query()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'status' => BookingStatus::PendingMatching,
            'requested_address' => '1 Example Road',
            'scheduled_at' => now()->addDay(),
        ]);

        $this->actingAs($otherCustomer, 'sanctum')
            ->postJson("/api/v1/customer/bookings/{$booking->id}/cancel")
            ->assertNotFound();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/customer/bookings/{$booking->id}/complete")
            ->assertStatus(409);
        $this->postJson("/api/v1/customer/bookings/{$booking->id}/review", ['rating' => 5])
            ->assertStatus(409);
        $this->postJson("/api/v1/customer/bookings/{$booking->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', BookingStatus::Cancelled->value);

        $booking->forceFill([
            'status' => BookingStatus::InProgress,
            'provider_id' => $provider->id,
        ])->save();
        $this->postJson("/api/v1/customer/bookings/{$booking->id}/complete")
            ->assertStatus(409);
        $booking->forceFill([
            'status' => BookingStatus::Completed,
            'completed_at' => now(),
        ])->save();
        $this->postJson("/api/v1/customer/bookings/{$booking->id}/review", [
            'rating' => 6,
        ])->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->postJson("/api/v1/customer/bookings/{$booking->id}/review", [
            'rating' => 5,
            'comment' => 'Great service',
        ])->assertCreated();
        $this->postJson("/api/v1/customer/bookings/{$booking->id}/review", [
            'rating' => 4,
        ])->assertStatus(409);
    }

    public function test_matching_offers_go_only_to_eligible_providers_and_assignment_requires_acceptance(): void
    {
        [$provider, $service] = $this->availableService();
        $provider->providerProfile()->update([
            'is_available' => true,
            'coverage_radius_km' => 15,
        ]);
        $outsideCoverage = $this->userWithRole(UserRole::ServiceProvider);
        $outsideCoverage->forceFill([
            'provider_verification_status' => ProviderVerificationStatus::Verified,
        ])->save();
        ProviderProfile::query()->create([
            'user_id' => $outsideCoverage->id,
            'business_name' => 'Outside coverage services',
            'identity_document_path' => 'provider-identity/outside.jpg',
            'latitude' => 40.2,
            'longitude' => -73.9,
            'is_available' => true,
            'coverage_radius_km' => 1,
        ]);
        Service::query()->create([
            'provider_id' => $outsideCoverage->id,
            'category_id' => $service->category_id,
            'name' => 'Deep cleaning',
            'is_active' => true,
        ]);
        $customer = $this->userWithRole(UserRole::Customer);
        $booking = Booking::query()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'status' => BookingStatus::PendingMatching,
            'requested_address' => '20 Main Street',
            'latitude' => 40.1,
            'longitude' => -73.9,
            'matching_attempt' => 1,
            'matching_started_at' => now(),
        ]);

        app(BookingMatcher::class)->offerNext($booking->id);

        $offer = ProviderOffer::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame($provider->id, $offer->provider_id);
        $this->assertSame(BookingStatus::Offered, $booking->fresh()->status);
        $this->assertNull($booking->fresh()->provider_id);

        $offerResponse = $this->actingAs($provider, 'sanctum')
            ->getJson('/api/v1/provider/offers')
            ->assertOk()
            ->assertJsonPath('data.0.id', $offer->id)
            ->assertJsonPath('data.0.service.name', $service->name);
        $this->assertArrayNotHasKey('customer', $offerResponse->json('data.0.booking'));
        $this->assertArrayNotHasKey('address', $offerResponse->json('data.0.booking'));
        $this->assertArrayNotHasKey('latitude', $offerResponse->json('data.0.booking'));
        $this->assertArrayNotHasKey('longitude', $offerResponse->json('data.0.booking'));

        $this->postJson("/api/v1/provider/offers/{$offer->id}/respond", [
            'decision' => 'accept',
        ])->assertOk()
            ->assertJsonPath('data.provider_id', $provider->id)
            ->assertJsonPath('data.status', BookingStatus::Matched->value);

        $this->assertSame(ProviderOfferStatus::Accepted, $offer->fresh()->status);
        $this->assertTrue($booking->fresh()->provider_was_available);
        $this->assertFalse($provider->providerProfile()->firstOrFail()->fresh()->is_available);
        $this->assertDatabaseHas('booking_matching_attempts', [
            'booking_id' => $booking->id,
            'attempt' => 1,
            'status' => 'accepted',
            'candidate_count' => 1,
            'offers_dispatched' => 1,
        ]);
    }

    public function test_parallel_offer_policy_dispatches_a_bounded_batch_and_records_the_attempt(): void
    {
        config([
            'neybor.matching.offer_policy.mode' => 'parallel',
            'neybor.matching.offer_policy.batch_size' => 2,
        ]);
        [$provider, $service] = $this->availableService();
        $provider->providerProfile()->update(['is_available' => true]);
        $secondProvider = $this->userWithRole(UserRole::ServiceProvider);
        $secondProvider->forceFill([
            'provider_verification_status' => ProviderVerificationStatus::Verified,
        ])->save();
        $secondProvider->markEmailAsVerified();
        ProviderProfile::query()->create([
            'user_id' => $secondProvider->id,
            'business_name' => 'Second local professional',
            'identity_document_path' => 'provider-identity/second.jpg',
            'latitude' => 40.01,
            'longitude' => -74,
            'is_available' => true,
            'coverage_radius_km' => 15,
        ]);
        Service::query()->create([
            'provider_id' => $secondProvider->id,
            'category_id' => $service->category_id,
            'name' => 'House cleaning',
            'description' => 'Residential cleaning',
            'is_active' => true,
        ]);
        $offlineProvider = $this->userWithRole(UserRole::ServiceProvider);
        $offlineProvider->forceFill([
            'provider_verification_status' => ProviderVerificationStatus::Verified,
        ])->save();
        $offlineProvider->markEmailAsVerified();
        ProviderProfile::query()->create([
            'user_id' => $offlineProvider->id,
            'business_name' => 'Offline professional',
            'identity_document_path' => 'provider-identity/offline.jpg',
            'latitude' => 40.02,
            'longitude' => -74,
            'is_available' => false,
            'coverage_radius_km' => 15,
        ]);
        Service::query()->create([
            'provider_id' => $offlineProvider->id,
            'category_id' => $service->category_id,
            'name' => 'Cleaning service',
            'is_active' => true,
        ]);
        $customer = $this->userWithRole(UserRole::Customer);
        $booking = Booking::query()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'status' => BookingStatus::PendingMatching,
            'requested_address' => '20 Main Street',
            'latitude' => 40.1,
            'longitude' => -73.9,
            'matching_attempt' => 1,
            'matching_started_at' => now(),
        ]);

        app(BookingMatcher::class)->offerNext($booking->id);

        $offers = ProviderOffer::query()->where('booking_id', $booking->id)->get();
        $this->assertCount(2, $offers);
        $this->assertCount(2, $offers->pluck('provider_id')->unique());
        $this->assertSame(BookingStatus::Offered, $booking->fresh()->status);
        $this->assertDatabaseHas('booking_matching_attempts', [
            'booking_id' => $booking->id,
            'attempt' => 1,
            'status' => 'offered',
            'candidate_count' => 2,
            'offers_dispatched' => 2,
        ]);

        app(BookingMatcher::class)->offerNext($booking->id);
        $this->assertCount(2, ProviderOffer::query()->where('booking_id', $booking->id)->get());
        $winner = $offers[0];
        $loser = $offers[1];
        $this->actingAs($winner->provider, 'sanctum')
            ->postJson("/api/v1/provider/offers/{$winner->id}/respond", ['decision' => 'accept'])
            ->assertOk();
        $this->actingAs($loser->provider, 'sanctum')
            ->postJson("/api/v1/provider/offers/{$loser->id}/respond", ['decision' => 'accept'])
            ->assertStatus(409);
        $this->assertSame(
            1,
            Booking::query()->where('provider_id', $winner->provider_id)
                ->whereIn('status', [
                    BookingStatus::Matched,
                    BookingStatus::Accepted,
                    BookingStatus::EnRoute,
                    BookingStatus::Arrived,
                    BookingStatus::InProgress,
                ])->count(),
        );
    }

    public function test_configured_distance_relevance_and_rating_weights_change_candidate_order(): void
    {
        [$farProvider, $requestedService] = $this->availableService();
        $farProvider->providerProfile()->update([
            'is_available' => true,
            'latitude' => 40,
            'longitude' => -74,
            'coverage_radius_km' => 25,
            'available_since' => now()->subHours(6),
        ]);
        $nearProvider = $this->userWithRole(UserRole::ServiceProvider);
        $nearProvider->forceFill([
            'provider_verification_status' => ProviderVerificationStatus::Verified,
        ])->save();
        $nearProvider->markEmailAsVerified();
        ProviderProfile::query()->create([
            'user_id' => $nearProvider->id,
            'business_name' => 'Nearby professional',
            'identity_document_path' => 'provider-identity/nearby.jpg',
            'latitude' => 40.09,
            'longitude' => -73.9,
            'is_available' => true,
            'coverage_radius_km' => 25,
            'available_since' => now(),
        ]);
        Service::query()->create([
            'provider_id' => $nearProvider->id,
            'category_id' => $requestedService->category_id,
            'name' => 'House cleaning',
            'description' => 'Residential cleaning',
            'is_active' => true,
        ]);
        $customer = $this->userWithRole(UserRole::Customer);
        $completed = Booking::query()->create([
            'customer_id' => $customer->id,
            'service_id' => $requestedService->id,
            'provider_id' => $farProvider->id,
            'status' => BookingStatus::Completed,
            'requested_address' => 'Previous customer address',
        ]);
        Review::query()->create([
            'booking_id' => $completed->id,
            'customer_id' => $customer->id,
            'provider_id' => $farProvider->id,
            'rating' => 5,
            'comment' => 'Excellent work',
        ]);
        $newBooking = function () use ($customer, $requestedService): Booking {
            return Booking::query()->create([
                'customer_id' => $customer->id,
                'service_id' => $requestedService->id,
                'status' => BookingStatus::PendingMatching,
                'requested_address' => 'Current customer address',
                'latitude' => 40.1,
                'longitude' => -73.9,
                'matching_attempt' => 1,
                'matching_started_at' => now(),
            ]);
        };
        $ranking = fn (float $distance, float $relevance, float $rating, float $availability): array => [
            'distance_weight' => $distance,
            'relevance_weight' => $relevance,
            'rating_weight' => $rating,
            'availability_weight' => $availability,
            'availability_freshness_minutes' => 120,
        ];
        $matcher = app(BookingMatcher::class);

        config(['neybor.matching.ranking' => $ranking(1, 0, 0, 0)]);
        $distanceBooking = $newBooking();
        $matcher->offerNext($distanceBooking->id);
        $distanceOffer = ProviderOffer::query()->where('booking_id', $distanceBooking->id)->firstOrFail();
        $this->assertSame($nearProvider->id, $distanceOffer->provider_id);
        $distanceOffer->update(['status' => ProviderOfferStatus::Rejected, 'responded_at' => now()]);

        config(['neybor.matching.ranking' => $ranking(0, 1, 0, 0)]);
        $relevanceBooking = $newBooking();
        $matcher->offerNext($relevanceBooking->id);
        $relevanceOffer = ProviderOffer::query()->where('booking_id', $relevanceBooking->id)->firstOrFail();
        $this->assertSame($farProvider->id, $relevanceOffer->provider_id);
        $relevanceOffer->update(['status' => ProviderOfferStatus::Rejected, 'responded_at' => now()]);

        config(['neybor.matching.ranking' => $ranking(0, 0, 1, 0)]);
        $ratingBooking = $newBooking();
        $matcher->offerNext($ratingBooking->id);
        $ratingOffer = ProviderOffer::query()->where('booking_id', $ratingBooking->id)->firstOrFail();
        $this->assertSame($farProvider->id, $ratingOffer->provider_id);
        $ratingOffer->update(['status' => ProviderOfferStatus::Rejected, 'responded_at' => now()]);

        config(['neybor.matching.ranking' => $ranking(0, 0, 0, 1)]);
        $availabilityBooking = $newBooking();
        $matcher->offerNext($availabilityBooking->id);
        $availabilityOffer = ProviderOffer::query()->where('booking_id', $availabilityBooking->id)->firstOrFail();
        $this->assertSame($nearProvider->id, $availabilityOffer->provider_id);
        $availabilityOffer->update(['status' => ProviderOfferStatus::Rejected, 'responded_at' => now()]);

        config(['neybor.matching.service_compatibility' => 'exact']);
        $exactBooking = $newBooking();
        $matcher->offerNext($exactBooking->id);
        $exactOffer = ProviderOffer::query()->where('booking_id', $exactBooking->id)->firstOrFail();
        $this->assertSame($farProvider->id, $exactOffer->provider_id);
    }

    public function test_provider_availability_requires_verification_and_service(): void
    {
        $provider = $this->userWithRole(UserRole::ServiceProvider);
        ProviderProfile::query()->create([
            'user_id' => $provider->id,
            'business_name' => 'Verified provider',
            'identity_document_path' => 'provider-identity/verified.jpg',
        ]);
        $this->actingAs($provider, 'sanctum')
            ->patchJson('/api/v1/provider/availability', [
                'is_available' => true,
                'latitude' => 6.335,
                'longitude' => 5.6037,
            ])->assertForbidden();

        $provider->forceFill([
            'provider_verification_status' => ProviderVerificationStatus::Verified,
        ])->save();
        $this->patchJson('/api/v1/provider/availability', [
            'is_available' => true,
            'latitude' => 6.335,
            'longitude' => 5.6037,
        ])->assertStatus(409);

        $category = ServiceCategory::query()->create([
            'name' => 'Repairs',
            'slug' => 'repairs',
            'is_active' => true,
        ]);
        Service::query()->create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'name' => 'Phone repair',
            'is_active' => true,
        ]);
        $this->patchJson('/api/v1/provider/availability', [
            'is_available' => true,
            'latitude' => 6.335,
            'longitude' => 5.6037,
            'coverage_radius_km' => 25,
        ])->assertOk()
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.coverage_radius_km', 25);
    }

    public function test_rejected_and_expired_offers_advance_matching_and_exhaustion_can_be_retried(): void
    {
        [$firstProvider, $service] = $this->availableService();
        $firstProvider->providerProfile()->update(['is_available' => true]);
        $secondProvider = $this->userWithRole(UserRole::ServiceProvider);
        $secondProvider->forceFill([
            'provider_verification_status' => ProviderVerificationStatus::Verified,
        ])->save();
        ProviderProfile::query()->create([
            'user_id' => $secondProvider->id,
            'business_name' => 'Second provider',
            'identity_document_path' => 'provider-identity/second.jpg',
            'latitude' => 40.1,
            'longitude' => -73.9,
            'is_available' => true,
            'coverage_radius_km' => 15,
        ]);
        Service::query()->create([
            'provider_id' => $secondProvider->id,
            'category_id' => $service->category_id,
            'name' => 'House cleaning',
            'description' => 'Residential cleaning',
            'is_active' => true,
        ]);

        $customer = $this->userWithRole(UserRole::Customer);
        $booking = Booking::query()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'status' => BookingStatus::PendingMatching,
            'requested_address' => '20 Main Street',
            'latitude' => 40.1,
            'longitude' => -73.9,
            'matching_attempt' => 1,
            'matching_started_at' => now(),
        ]);
        $matcher = app(BookingMatcher::class);
        $matcher->offerNext($booking->id);
        $firstOffer = ProviderOffer::query()->where('booking_id', $booking->id)->firstOrFail();

        $this->actingAs($firstOffer->provider, 'sanctum')
            ->postJson("/api/v1/provider/offers/{$firstOffer->id}/respond", [
                'decision' => 'reject',
            ])->assertOk();
        $matcher->offerNext($booking->id);
        $secondOffer = ProviderOffer::query()
            ->where('booking_id', $booking->id)
            ->where('status', ProviderOfferStatus::Pending)
            ->firstOrFail();
        $this->assertNotSame($firstOffer->provider_id, $secondOffer->provider_id);

        $secondOffer->update(['expires_at' => now()->subSecond()]);
        $this->artisan('bookings:expire-provider-offers')->assertSuccessful();
        $matcher->offerNext($booking->id);
        $this->assertSame(BookingStatus::NoProviderAvailable, $booking->fresh()->status);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/customer/bookings/{$booking->id}/retry-matching")
            ->assertOk()
            ->assertJsonPath('data.matching.attempt', 2);
        $matcher->offerNext($booking->id);
        $this->assertSame(BookingStatus::Offered, $booking->fresh()->status);
        $this->assertSame(2, $booking->fresh()->matching_attempt);
    }

    public function test_matching_job_failures_are_recorded_and_customer_can_retry(): void
    {
        $customer = $this->userWithRole(UserRole::Customer);
        [, $service] = $this->availableService();
        $booking = Booking::query()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'status' => BookingStatus::PendingMatching,
            'requested_address' => '20 Main Street',
            'latitude' => 40.1,
            'longitude' => -73.9,
            'matching_attempt' => 1,
            'matching_started_at' => now(),
        ]);

        app(BookingMatcher::class)->markFailed($booking->id, new \RuntimeException('Queue worker failed.'));

        $this->assertSame(BookingStatus::NoProviderAvailable, $booking->fresh()->status);
        $this->assertDatabaseHas('booking_matching_attempts', [
            'booking_id' => $booking->id,
            'attempt' => 1,
            'status' => 'failed',
            'failure_reason' => 'Queue worker failed.',
        ]);
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/customer/bookings/{$booking->id}/retry-matching")
            ->assertOk()
            ->assertJsonPath('data.matching.attempt', 2);
        $this->assertDatabaseHas('booking_matching_attempts', [
            'booking_id' => $booking->id,
            'attempt' => 2,
            'status' => 'no_match',
        ]);
    }

    public function test_saved_locations_profile_tickets_and_notifications_are_scoped_to_the_owner(): void
    {
        $customer = $this->userWithRole(UserRole::Customer);
        $otherCustomer = $this->userWithRole(UserRole::Customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/customer/saved-locations', [
                'label' => 'Home',
                'address' => '4 Oak Ave',
                'latitude' => 41.2,
                'longitude' => -72.4,
                'is_default' => true,
            ])->assertCreated();

        $locationId = $customer->savedLocations()->firstOrFail()->id;
        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/customer/saved-locations', [
                'label' => 'Office',
                'address' => '8 Pine Street',
                'latitude' => 41.3,
                'longitude' => -72.5,
                'is_default' => true,
            ])->assertCreated();
        $this->assertFalse($customer->savedLocations()->findOrFail($locationId)->is_default);
        $office = $customer->savedLocations()->where('label', 'Office')->firstOrFail();
        $this->patchJson("/api/v1/customer/saved-locations/{$office->id}", ['is_default' => false])->assertOk();
        $this->assertTrue($customer->savedLocations()->findOrFail($locationId)->fresh()->is_default);
        $this->deleteJson("/api/v1/customer/saved-locations/{$office->id}")->assertNoContent();

        $this->actingAs($otherCustomer, 'sanctum')
            ->patchJson("/api/v1/customer/saved-locations/{$locationId}", ['label' => 'Stolen'])
            ->assertNotFound();

        $this->actingAs($customer, 'sanctum')
            ->patchJson('/api/v1/customer/profile', ['name' => 'Updated Name'])
            ->assertOk()->assertJsonPath('data.name', 'Updated Name');
        $this->postJson('/api/v1/customer/support-tickets', [
            'subject' => 'Question',
            'message' => 'Please assist.',
        ])->assertCreated();
        $this->getJson('/api/v1/customer/support-tickets')
            ->assertOk()->assertJsonPath('data.0.subject', 'Question');
        $this->actingAs($otherCustomer, 'sanctum')
            ->getJson('/api/v1/customer/support-tickets')
            ->assertOk()->assertJsonPath('data', []);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/customer/push-tokens', [
                'token' => 'push-token-123',
                'platform' => 'ios',
            ])->assertCreated();
        $this->actingAs($otherCustomer, 'sanctum')
            ->postJson('/api/v1/customer/push-tokens', [
                'token' => 'push-token-123',
                'platform' => 'ios',
            ])->assertStatus(409);

        $notification = $customer->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test.notification',
            'data' => ['message' => 'Private'],
        ]);
        $this->postJson("/api/v1/customer/notifications/{$notification->id}/read")
            ->assertNotFound();
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/customer/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id);
    }

    public function test_email_verification_accepts_the_signed_mobile_link(): void
    {
        $customer = User::factory()->unverified()->create();
        $customer->forceFill(['role' => UserRole::Customer])->save();
        $url = URL::temporarySignedRoute(
            'api.auth.email.verify.link',
            now()->addMinutes(10),
            ['id' => $customer->id, 'hash' => sha1($customer->email)],
        );

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/auth/email/verify', ['verification_url' => $url])
            ->assertOk()
            ->assertJsonPath('data.email_verified', true);
        $this->assertNotNull($customer->fresh()->email_verified_at);
    }

    public function test_public_service_search_exposes_only_active_verified_services(): void
    {
        [, $service] = $this->availableService();
        $this->getJson('/api/v1/customer/services?search=Window')
            ->assertOk()->assertJsonPath('data.0.id', $service->id)
            ->assertJsonPath('data.0.provider.name', $service->provider->name);

        $service->provider->forceFill([
            'provider_verification_status' => ProviderVerificationStatus::Rejected,
        ])->save();
        $this->getJson('/api/v1/customer/services')->assertOk()->assertJsonPath('data', []);
    }

    private function availableService(): array
    {
        $provider = $this->userWithRole(UserRole::ServiceProvider);
        $provider->forceFill(['provider_verification_status' => ProviderVerificationStatus::Verified])->save();
        ProviderProfile::query()->create([
            'user_id' => $provider->id,
            'business_name' => 'Local professional',
            'bio' => 'Local professional',
            'identity_document_path' => 'provider-identity/provider.jpg',
            'latitude' => 40,
            'longitude' => -74,
        ]);
        $category = ServiceCategory::query()->create([
            'name' => 'Cleaning',
            'slug' => 'cleaning',
            'is_active' => true,
        ]);
        $service = Service::query()->create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'name' => 'Window cleaning',
            'description' => 'Residential windows',
            'base_price' => 50,
            'is_active' => true,
        ]);

        return [$provider, $service];
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'role' => $role,
            'provider_verification_status' => $role === UserRole::ServiceProvider
                ? ProviderVerificationStatus::Pending
                : ProviderVerificationStatus::NotApplicable,
        ])->save();

        return $user;
    }
}
