<?php

namespace Tests\Feature;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeAuditLog;
use App\Models\ProviderProfile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_cannot_access_provider_or_administrator_operations(): void
    {
        $customer = $this->userWithRole(UserRole::Customer);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/customer/account')
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id);

        $this->getJson('/api/v1/provider/account')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_only_verified_providers_can_access_provider_operations(): void
    {
        $provider = $this->userWithRole(UserRole::ServiceProvider);

        $this->actingAs($provider, 'sanctum')
            ->getJson('/api/v1/provider/account')
            ->assertOk()
            ->assertJsonPath('capabilities.manage_services', false);

        $this->getJson('/api/v1/provider/operations')
            ->assertForbidden()
            ->assertJsonPath('code', 'provider_verification_required');

        $provider->forceFill([
            'provider_verification_status' => ProviderVerificationStatus::Verified,
        ])->save();

        $this->getJson('/api/v1/provider/operations')
            ->assertOk()
            ->assertJsonPath('status', 'verified');
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_administrators_can_change_roles_suspend_users_and_review_audit_logs(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);
        $customer = $this->userWithRole(UserRole::Customer);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$customer->id}/role", [
                'role' => UserRole::ServiceProvider->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::ServiceProvider->value)
            ->assertJsonPath('data.provider_verification_status', ProviderVerificationStatus::Pending->value);

        $this->postJson("/api/v1/admin/users/{$customer->id}/suspend", [
            'reason' => 'Repeated policy violations',
        ])->assertOk()->assertJsonPath('data.suspension_reason', 'Repeated policy violations');

        $this->actingAs($customer->refresh(), 'sanctum');
        $this->getJson('/api/v1/provider/account')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_suspended');

        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.actor_user_id', $admin->id);

        $this->assertDatabaseHas('administrative_audit_logs', [
            'actor_user_id' => $admin->id,
            'target_user_id' => $customer->id,
            'action' => 'user.suspended',
        ]);
    }

    public function test_administrators_can_verify_providers_and_rejections_require_a_reason(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);
        $provider = $this->userWithRole(UserRole::ServiceProvider);
        $provider->forceFill(['phone' => '+2348000000000'])->save();
        ProviderProfile::query()->create([
            'user_id' => $provider->id,
            'business_name' => 'Verified provider',
            'bio' => 'Professional home services',
            'identity_document_path' => 'provider-identity/provider.jpg',
            'latitude' => 6.335,
            'longitude' => 5.6037,
        ]);
        $category = ServiceCategory::query()->create([
            'name' => 'Repairs',
            'slug' => 'repairs',
            'is_active' => true,
        ]);
        Service::query()->create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'name' => 'Electrical repairs',
            'description' => 'Residential electrical repairs and installations.',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/providers/{$provider->id}/verification", [
                'decision' => ProviderVerificationStatus::Rejected->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/admin/providers/{$provider->id}/verification", [
            'decision' => ProviderVerificationStatus::Verified->value,
            'reason' => 'Identity and professional profile reviewed.',
        ])->assertOk()->assertJsonPath(
            'data.provider_verification_status',
            ProviderVerificationStatus::Verified->value,
        );

        $this->assertDatabaseHas('administrative_audit_logs', [
            'actor_user_id' => $admin->id,
            'target_user_id' => $provider->id,
            'action' => 'provider.verification_decided',
        ]);
    }

    public function test_administrators_cannot_change_their_own_role_or_manage_other_administrators(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);
        $anotherAdmin = $this->userWithRole(UserRole::Administrator);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$admin->id}/role", [
                'role' => UserRole::Customer->value,
            ])
            ->assertForbidden();

        $this->postJson("/api/v1/admin/users/{$admin->id}/suspend", [
            'reason' => 'Attempted self-suspension',
        ])->assertForbidden();

        $this->patchJson("/api/v1/admin/users/{$anotherAdmin->id}/role", [
            'role' => UserRole::Customer->value,
        ])->assertForbidden();

        $this->postJson("/api/v1/admin/users/{$anotherAdmin->id}/suspend", [
            'reason' => 'Attempted administrator suspension',
        ])->assertForbidden();

        $this->assertDatabaseCount('administrative_audit_logs', 0);
    }

    public function test_administrators_can_unsuspend_an_account(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);
        $customer = $this->userWithRole(UserRole::Customer);
        $customer->forceFill([
            'suspended_at' => now(),
            'suspension_reason' => 'Manual review',
        ])->save();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$customer->id}/unsuspend", [
                'reason' => 'Suspension review completed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.suspended_at', null);

        $this->actingAs($customer->refresh(), 'sanctum')
            ->getJson('/api/v1/customer/account')
            ->assertOk();

        $this->assertDatabaseHas('administrative_audit_logs', [
            'actor_user_id' => $admin->id,
            'target_user_id' => $customer->id,
            'action' => 'user.unsuspended',
        ]);
    }

    public function test_suspension_revokes_existing_personal_access_tokens(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);
        $customer = $this->userWithRole(UserRole::Customer);
        $customer->createToken('mobile');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$customer->id}/suspend", [
                'reason' => 'Account under review',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $customer->id,
        ]);
    }

    public function test_administrative_audit_logs_cannot_be_updated_or_deleted(): void
    {
        $log = AdministrativeAuditLog::query()->create([
            'actor_role' => UserRole::Administrator->value,
            'target_role' => UserRole::Customer->value,
            'action' => 'test.immutable',
            'created_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $log->delete();
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
