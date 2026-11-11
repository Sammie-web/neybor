<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Service;
use App\Models\User;
use App\Models\WaitlistRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_log_in_but_public_registration_cannot_create_an_administrator(): void
    {
        $admin = User::factory()->create(['email' => 'ops@example.test']);
        $admin->forceFill(['role' => UserRole::Administrator])->save();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.role', 'administrator');

        $token = $login->json('token');
        $this->withToken($token)->getJson('/api/v1/admin/overview')->assertOk();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Not an admin',
            'email' => 'new-admin@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'administrator',
        ])->assertCreated()->assertJsonPath('data.role', 'customer');
    }

    public function test_admin_settings_persist_without_enabling_payment_collection_and_create_an_audit_record(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/settings', [
                'pricing_model' => 'customer_quotation',
                'payment_gateways' => ['paystack', 'flutterwave'],
            ])
            ->assertOk()
            ->assertJsonPath('data.pricing_model', 'customer_quotation')
            ->assertJsonPath('data.payment_gateways.0', 'paystack')
            ->assertJsonPath('data.payment_gateways.1', 'flutterwave')
            ->assertJsonPath('data.payments_enabled', false);

        $this->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.pricing_model', 'customer_quotation')
            ->assertJsonPath('data.payment_gateways.1', 'flutterwave')
            ->assertJsonPath('data.commission_rate', null);

        $this->assertDatabaseHas('administrative_audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'platform.settings_updated',
        ]);
        $this->assertDatabaseHas('platform_settings', ['key' => 'marketplace']);
    }

    public function test_waitlist_is_public_validated_deduplicated_per_audience_and_visible_to_administrators(): void
    {
        $this->postJson('/api/v1/waitlist', [
            'name' => 'Ada Customer',
            'email' => 'ADA@example.test',
            'role' => 'customer',
        ])->assertCreated();

        $this->postJson('/api/v1/waitlist', [
            'name' => 'Ada Again',
            'email' => 'ada@example.test',
            'role' => 'customer',
        ])->assertOk()->assertJsonPath('code', 'already_registered');

        $this->postJson('/api/v1/waitlist', [
            'name' => 'Ada Provider',
            'email' => 'ada@example.test',
            'role' => 'provider',
            'business_name' => 'Ada Electrical',
        ])->assertCreated();

        $this->postJson('/api/v1/waitlist', [
            'name' => 'Missing business',
            'email' => 'provider@example.test',
            'role' => 'provider',
        ])->assertUnprocessable()->assertJsonValidationErrors('business_name');

        $this->assertSame(2, WaitlistRegistration::query()->count());
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/waitlist?role=provider')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.business_name', 'Ada Electrical');
    }

    public function test_admin_can_create_a_category_and_moderate_a_service_with_audited_reasons(): void
    {
        $admin = $this->admin();
        $categoryResponse = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/categories', [
            'name' => 'Home repair',
            'description' => 'Local household maintenance.',
        ])->assertCreated()->assertJsonPath('data.slug', 'home-repair');

        $provider = User::factory()->create();
        $provider->forceFill(['role' => UserRole::ServiceProvider])->save();
        $service = Service::query()->create([
            'provider_id' => $provider->id,
            'category_id' => $categoryResponse->json('data.id'),
            'name' => 'Door repairs',
            'description' => 'Door repair and adjustment service.',
            'is_active' => true,
        ]);

        $this->patchJson("/api/v1/admin/services/{$service->id}/moderation", [
            'is_active' => false,
            'reason' => 'Listing does not meet the service quality guidelines.',
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->getJson('/api/v1/admin/services?active=false&search=Door')
            ->assertOk()->assertJsonPath('total', 1);
        $this->assertDatabaseHas('administrative_audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'service.moderation_changed',
        ]);
    }

    public function test_non_administrators_cannot_access_administrative_data_or_mutations(): void
    {
        $customer = User::factory()->create();
        $customer->forceFill(['role' => UserRole::Customer])->save();
        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/admin/reports')->assertForbidden();
        $this->patchJson('/api/v1/admin/settings', ['payment_gateways' => ['paystack']])->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Administrator])->save();

        return $admin;
    }
}
