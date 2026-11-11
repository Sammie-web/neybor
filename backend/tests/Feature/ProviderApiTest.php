<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeAuditLog;
use App\Models\Booking;
use App\Models\ProviderProfile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Notifications\ProviderOfferReceived;
use App\Services\BookingMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProviderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_can_register_build_a_profile_and_submit_private_identity_for_approval(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'Nosa Provider',
            'email' => 'nosa@example.test',
            'phone' => '+2348000000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => UserRole::ServiceProvider->value,
        ])->assertCreated()
            ->assertJsonPath('data.role', UserRole::ServiceProvider->value)
            ->assertJsonPath('data.provider_verification_status', ProviderVerificationStatus::Pending->value);
        $provider = User::query()->findOrFail($registration->json('data.id'));
        $this->assertDatabaseHas('provider_profiles', ['user_id' => $provider->id]);

        $provider->markEmailAsVerified();
        $this->actingAs($provider, 'sanctum');
        $this->patchJson('/api/v1/provider/profile', [
            'business_name' => 'Nosa Home Services',
            'bio' => 'Trusted home electrical repairs and installations.',
            'skills' => ['Electrical repairs', 'Wiring'],
            'latitude' => 6.335,
            'longitude' => 5.6037,
            'coverage_radius_km' => 25,
        ])->assertOk()->assertJsonPath('data.business_name', 'Nosa Home Services');

        $category = ServiceCategory::query()->create([
            'name' => 'Electrical',
            'slug' => 'electrical',
            'is_active' => true,
        ]);
        $service = $this->postJson('/api/v1/provider/services', [
            'category_id' => $category->id,
            'name' => 'Home electrical repair',
            'description' => 'Repairs and safe wiring installations for homes.',
            'base_price' => 5000,
            'price_unit' => 'visit',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Home electrical repair')
            ->json('data.id');

        $this->postJson('/api/v1/provider/profile/photo', [
            'photo' => UploadedFile::fake()->image('profile.jpg'),
        ])->assertOk()->assertJsonStructure(['data' => ['avatar_url']]);
        $this->postJson('/api/v1/provider/portfolio', [
            'image' => UploadedFile::fake()->image('work.jpg'),
            'caption' => 'Completed wiring',
        ])->assertCreated()->assertJsonPath('data.caption', 'Completed wiring');
        $this->postJson('/api/v1/provider/identity-document', [
            'document' => UploadedFile::fake()->image('identity.jpg'),
            'document_type' => 'national_id',
        ])->assertCreated()->assertJsonStructure(['data' => ['identity_document_type', 'identity_submitted_at']]);

        $this->postJson('/api/v1/provider/submit-for-approval')
            ->assertOk()
            ->assertJsonPath('data.verification_status', ProviderVerificationStatus::Pending->value);
        $this->getJson('/api/v1/provider/profile')
            ->assertOk()
            ->assertJsonPath('data.has_identity_document', true)
            ->assertJsonMissingPath('data.identity_document_path')
            ->assertJsonPath('data.services.0.id', $service);
        Storage::disk('local')->assertExists($provider->providerProfile->fresh()->identity_document_path);
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Administrator])->save();
        $this->actingAs($admin, 'sanctum')
            ->get("/api/v1/admin/providers/{$provider->id}/identity-document")
            ->assertOk();
        $this->assertSame(
            1,
            AdministrativeAuditLog::query()->where('action', 'provider.identity_document_viewed')->count(),
        );
    }

    public function test_only_approved_eligible_providers_receive_matching_requests_and_notifications(): void
    {
        $provider = $this->providerReadyForMatching();
        $category = $provider->services()->firstOrFail()->category;
        $customer = User::factory()->create();
        $customer->forceFill(['role' => UserRole::Customer])->save();
        $booking = Booking::query()->create([
            'customer_id' => $customer->id,
            'service_id' => $provider->services()->firstOrFail()->id,
            'status' => BookingStatus::PendingMatching,
            'requested_address' => '20 Main Street',
            'latitude' => 6.335,
            'longitude' => 5.6037,
            'matching_attempt' => 1,
        ]);

        app(BookingMatcher::class)->offerNext($booking->id);

        $this->assertDatabaseHas('provider_offers', [
            'booking_id' => $booking->id,
            'provider_id' => $provider->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $provider->id,
            'type' => ProviderOfferReceived::class,
        ]);

        $provider->providerProfile()->update(['identity_document_path' => null]);
        $nextBooking = $booking->replicate();
        $nextBooking->save();
        app(BookingMatcher::class)->offerNext($nextBooking->id);
        $this->assertSame(BookingStatus::NoProviderAvailable, $nextBooking->fresh()->status);
    }

    public function test_provider_booking_workflow_tracks_completion_amount_and_dashboard_earnings(): void
    {
        $provider = $this->providerReadyForMatching();
        $customer = User::factory()->create();
        $customer->forceFill(['role' => UserRole::Customer])->save();
        $booking = Booking::query()->create([
            'customer_id' => $customer->id,
            'provider_id' => $provider->id,
            'service_id' => $provider->services()->firstOrFail()->id,
            'status' => BookingStatus::Matched,
            'requested_address' => '4 Oak Avenue',
            'latitude' => 6.335,
            'longitude' => 5.6037,
            'provider_was_available' => true,
        ]);
        $this->actingAs($provider, 'sanctum');
        $this->patchJson("/api/v1/provider/bookings/{$booking->id}", [
            'status' => BookingStatus::Accepted->value,
        ])->assertOk();
        $this->patchJson("/api/v1/provider/bookings/{$booking->id}", [
            'status' => BookingStatus::Arrived->value,
        ])->assertStatus(409);
        $this->patchJson("/api/v1/provider/bookings/{$booking->id}", [
            'status' => BookingStatus::EnRoute->value,
        ])->assertOk()->assertJsonPath('data.status', BookingStatus::EnRoute->value);
        $this->patchJson("/api/v1/provider/bookings/{$booking->id}", [
            'status' => BookingStatus::Arrived->value,
        ])->assertOk()->assertJsonPath('data.status', BookingStatus::Arrived->value);
        $this->patchJson("/api/v1/provider/bookings/{$booking->id}", [
            'status' => BookingStatus::InProgress->value,
        ])->assertOk();
        $this->patchJson("/api/v1/provider/bookings/{$booking->id}", [
            'status' => BookingStatus::Completed->value,
        ])->assertUnprocessable();
        $this->patchJson("/api/v1/provider/bookings/{$booking->id}", [
            'status' => BookingStatus::Completed->value,
            'quote_amount' => 12500,
        ])->assertOk()
            ->assertJsonPath('data.status', BookingStatus::Completed->value)
            ->assertJsonPath('data.quote_amount', '12500.00');
        $this->assertTrue($provider->providerProfile()->firstOrFail()->fresh()->is_available);
        $this->assertSame(5, $booking->statusEvents()->count());

        $this->getJson('/api/v1/provider/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.completed_jobs', 1)
            ->assertJsonPath('data.stats.earnings', 12500);
        $this->getJson('/api/v1/provider/bookings?status=completed')
            ->assertOk()->assertJsonPath('data.0.id', $booking->id);
    }

    public function test_provider_cancellation_requires_reason_rematches_and_excludes_the_cancelling_provider(): void
    {
        $provider = $this->providerReadyForMatching();
        $service = $provider->services()->firstOrFail();
        $nextProvider = User::factory()->create(['phone' => '+2348000000001']);
        $nextProvider->forceFill([
            'role' => UserRole::ServiceProvider,
            'provider_verification_status' => ProviderVerificationStatus::Verified,
        ])->save();
        $nextProvider->markEmailAsVerified();
        ProviderProfile::query()->create([
            'user_id' => $nextProvider->id,
            'business_name' => 'Second local service',
            'bio' => 'Local home repair professionals.',
            'identity_document_path' => 'provider-identity/second.jpg',
            'latitude' => 6.335,
            'longitude' => 5.6037,
            'is_available' => true,
            'coverage_radius_km' => 25,
        ]);
        Service::query()->create([
            'provider_id' => $nextProvider->id,
            'category_id' => $service->category_id,
            'name' => 'Electrical installations',
            'description' => 'Electrical installation and repair service.',
            'is_active' => true,
        ]);
        $customer = User::factory()->create();
        $customer->forceFill(['role' => UserRole::Customer])->save();
        $booking = Booking::query()->create([
            'customer_id' => $customer->id,
            'provider_id' => $provider->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Accepted,
            'requested_address' => '4 Oak Avenue',
            'latitude' => 6.335,
            'longitude' => 5.6037,
            'matching_attempt' => 1,
            'provider_was_available' => true,
        ]);

        $this->actingAs($provider, 'sanctum')
            ->postJson("/api/v1/provider/bookings/{$booking->id}/cancel", ['reason' => 'No'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $response = $this->postJson("/api/v1/provider/bookings/{$booking->id}/cancel", [
            'reason' => 'Unexpected emergency prevents me from attending.',
        ])->assertOk()
            ->assertJsonPath('data.provider_id', null)
            ->assertJsonPath('data.matching_attempt', 2);

        $this->assertDatabaseHas('booking_provider_exclusions', [
            'booking_id' => $booking->id,
            'provider_id' => $provider->id,
        ]);
        $this->assertDatabaseHas('booking_status_events', [
            'booking_id' => $booking->id,
            'from_status' => BookingStatus::Accepted->value,
            'to_status' => BookingStatus::PendingMatching->value,
            'reason' => 'Unexpected emergency prevents me from attending.',
        ]);
        $this->assertTrue($provider->providerProfile()->firstOrFail()->fresh()->is_available);
        $this->assertDatabaseHas('provider_offers', [
            'booking_id' => $booking->id,
            'provider_id' => $nextProvider->id,
            'matching_attempt' => 2,
        ]);
        $this->assertDatabaseMissing('provider_offers', [
            'booking_id' => $booking->id,
            'provider_id' => $provider->id,
            'matching_attempt' => 2,
        ]);
        $this->assertSame(BookingStatus::PendingMatching->value, $response->json('data.status'));
    }

    public function test_provider_cannot_cancel_after_arrival_or_starting_service(): void
    {
        $provider = $this->providerReadyForMatching();
        $customer = User::factory()->create();
        $customer->forceFill(['role' => UserRole::Customer])->save();
        $booking = Booking::query()->create([
            'customer_id' => $customer->id,
            'provider_id' => $provider->id,
            'service_id' => $provider->services()->firstOrFail()->id,
            'status' => BookingStatus::Arrived,
            'requested_address' => '4 Oak Avenue',
        ]);

        $this->actingAs($provider, 'sanctum')
            ->postJson("/api/v1/provider/bookings/{$booking->id}/cancel", [
                'reason' => 'I cannot do this job now.',
            ])->assertStatus(409)
            ->assertJsonPath('code', 'provider_cancellation_not_allowed');
        $this->assertSame(BookingStatus::Arrived, $booking->fresh()->status);
    }

    public function test_customers_cannot_manage_provider_profile_or_services(): void
    {
        $customer = User::factory()->create();
        $customer->forceFill(['role' => UserRole::Customer])->save();
        $provider = $this->providerReadyForMatching();
        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/provider/dashboard')
            ->assertForbidden();
        $this->get("/api/v1/admin/providers/{$provider->id}/identity-document")
            ->assertForbidden();
    }

    private function providerReadyForMatching(): User
    {
        $provider = User::factory()->create(['phone' => '+2348000000000']);
        $provider->forceFill([
            'role' => UserRole::ServiceProvider,
            'provider_verification_status' => ProviderVerificationStatus::Verified,
        ])->save();
        ProviderProfile::query()->create([
            'user_id' => $provider->id,
            'business_name' => 'Nosa Home Services',
            'bio' => 'Trusted local home repair professionals.',
            'identity_document_path' => 'provider-identity/identity.jpg',
            'latitude' => 6.335,
            'longitude' => 5.6037,
            'is_available' => true,
            'coverage_radius_km' => 25,
        ]);
        $category = ServiceCategory::query()->create([
            'name' => 'Electrical',
            'slug' => 'electrical',
            'is_active' => true,
        ]);
        Service::query()->create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'name' => 'Electrical repairs',
            'description' => 'Safe home wiring and repair work.',
            'is_active' => true,
        ]);

        return $provider;
    }
}
