<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AdministrativeAuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ProviderVerificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::enum(ProviderVerificationStatus::class)],
            'suspended' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $providers = User::query()
            ->where('role', UserRole::ServiceProvider)
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query
                ->where('provider_verification_status', $filters['status']))
            ->when(isset($filters['suspended']), fn ($query) => filter_var($filters['suspended'], FILTER_VALIDATE_BOOLEAN)
                ? $query->whereNotNull('suspended_at')
                : $query->whereNull('suspended_at'))
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters): void {
                $search = $filters['search'];
                $query->where(function ($builder) use ($search): void {
                    $builder->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('providerProfile', fn ($profile) => $profile
                            ->where('business_name', 'like', "%{$search}%"));
                });
            })
            ->with('providerProfile:user_id,business_name,identity_document_type,identity_submitted_at,latitude,longitude')
            ->withCount(['services' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('id')
            ->paginate(20, [
                'id',
                'name',
                'email',
                'provider_verification_status',
                'suspended_at',
                'created_at',
            ]);

        return response()->json($providers);
    }

    public function decide(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in([
                ProviderVerificationStatus::Verified->value,
                ProviderVerificationStatus::Rejected->value,
            ])],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        abort_unless(
            $user->role === UserRole::ServiceProvider,
            Response::HTTP_NOT_FOUND,
            'Provider not found.',
        );
        if ($validated['decision'] === ProviderVerificationStatus::Verified->value) {
            $user->loadMissing('providerProfile');
            abort_if(
                ! $user->hasVerifiedEmail()
                    || ! filled($user->phone)
                    || ! filled($user->providerProfile?->business_name)
                    || ! filled($user->providerProfile?->bio)
                    || ! $user->providerProfile?->identity_document_path
                    || $user->providerProfile?->latitude === null
                    || $user->providerProfile?->longitude === null
                    || ! $user->services()->where('is_active', true)->exists(),
                422,
                'Provider must verify contact details, submit identity documents, complete a professional profile, set service coverage, and publish an active service before approval.',
            );
        }

        DB::transaction(function () use ($request, $user, $validated): void {
            $provider = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless(
                $provider->role === UserRole::ServiceProvider,
                Response::HTTP_NOT_FOUND,
                'Provider not found.',
            );

            $previousStatus = $provider->provider_verification_status;
            $newStatus = ProviderVerificationStatus::from($validated['decision']);

            if ($previousStatus === $newStatus) {
                return;
            }

            $provider->provider_verification_status = $newStatus;
            $provider->save();
            if ($newStatus !== ProviderVerificationStatus::Verified) {
                $provider->providerProfile()->update([
                    'is_available' => false,
                    'available_since' => null,
                ]);
            }

            AdministrativeAuditLog::query()->create([
                'actor_user_id' => $request->user()->id,
                'actor_role' => $request->user()->role->value,
                'target_user_id' => $provider->id,
                'target_role' => $provider->role->value,
                'action' => 'provider.verification_decided',
                'context' => array_filter([
                    'from' => $previousStatus->value,
                    'to' => $newStatus->value,
                    'reason' => $validated['reason'] ?? null,
                ], static fn (?string $value): bool => $value !== null),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        });

        return response()->json([
            'data' => [
                'id' => $user->id,
                'role' => $user->role->value,
                'provider_verification_status' => $user->refresh()->provider_verification_status->value,
            ],
        ]);
    }
}
