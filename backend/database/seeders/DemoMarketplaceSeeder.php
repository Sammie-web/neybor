<?php

namespace Database\Seeders;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Models\ProviderProfile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

class DemoMarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('Demo marketplace data cannot be seeded in production.');
        }

        DB::transaction(function (): void {
            $category = ServiceCategory::query()->firstOrCreate(
                ['slug' => 'electrical'],
                ['name' => 'Electrical', 'is_active' => true],
            );
            $provider = User::query()->firstOrCreate(
                ['email' => 'demo-electrician@neybor.test'],
                [
                    'name' => 'Benin City Electrician',
                    'phone' => '+2348000000100',
                    'password' => Hash::make(Str::random(48)),
                    'email_verified_at' => now(),
                    'role' => UserRole::ServiceProvider,
                    'provider_verification_status' => ProviderVerificationStatus::Verified,
                ],
            );
            $provider->forceFill([
                'role' => UserRole::ServiceProvider,
                'provider_verification_status' => ProviderVerificationStatus::Verified,
                'email_verified_at' => $provider->email_verified_at ?? now(),
            ])->save();
            ProviderProfile::query()->updateOrCreate(
                ['user_id' => $provider->id],
                [
                    'business_name' => 'Benin Home Electrical',
                    'bio' => 'Electrical installation and repair services around Benin City.',
                    'latitude' => 6.335,
                    'longitude' => 5.6037,
                    'coverage_radius_km' => 25,
                    'is_available' => false,
                    'available_since' => null,
                    'identity_document_path' => 'seed-data/demo-identity-placeholder',
                    'identity_document_type' => 'national_id',
                    'identity_submitted_at' => now(),
                ],
            );
            Service::query()->firstOrCreate(
                [
                    'provider_id' => $provider->id,
                    'category_id' => $category->id,
                    'name' => 'Electrical repairs',
                ],
                [
                    'description' => 'Home electrical troubleshooting, repairs, and installations.',
                    'base_price' => 5000,
                    'price_unit' => 'per job',
                    'is_active' => true,
                ],
            );
        });
    }
}
