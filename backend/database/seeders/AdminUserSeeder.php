<?php

namespace Database\Seeders;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name = trim((string) env('ADMIN_NAME'));
        $email = strtolower(trim((string) env('ADMIN_EMAIL')));
        $password = (string) env('ADMIN_PASSWORD');

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 16) {
            throw new RuntimeException(
                'Set ADMIN_NAME, a valid ADMIN_EMAIL, and an ADMIN_PASSWORD of at least 16 characters.',
            );
        }

        DB::transaction(function () use ($name, $email, $password): void {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => $password],
            );

            if (! $user->wasRecentlyCreated && $user->role !== UserRole::Administrator) {
                throw new RuntimeException(
                    'The configured administrator email already belongs to a non-administrator account.',
                );
            }

            if ($user->wasRecentlyCreated) {
                $user->forceFill([
                    'role' => UserRole::Administrator,
                    'provider_verification_status' => ProviderVerificationStatus::NotApplicable,
                ])->save();
            }
        });
    }
}
