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

class UserManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'role' => ['sometimes', 'string', Rule::enum(UserRole::class)],
            'suspended' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->when(isset($filters['role']), fn ($query) => $query->where('role', $filters['role']))
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters): void {
                $search = $filters['search'];
                $query->where(fn ($builder) => $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%"));
            })
            ->when(
                array_key_exists('suspended', $filters),
                fn ($query) => filter_var($filters['suspended'], FILTER_VALIDATE_BOOLEAN)
                    ? $query->whereNotNull('suspended_at')
                    : $query->whereNull('suspended_at'),
            )
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        $users->through(fn (User $user): array => $this->userSummary($user));

        return response()->json($users);
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => [
                'required',
                'string',
                Rule::in([UserRole::Customer->value, UserRole::ServiceProvider->value]),
            ],
        ]);

        abort_if($request->user()->is($user), Response::HTTP_FORBIDDEN, 'Administrators cannot change their own role.');
        abort_if(
            $user->role === UserRole::Administrator,
            Response::HTTP_FORBIDDEN,
            'Administrator roles cannot be changed through this endpoint.',
        );

        DB::transaction(function () use ($request, $user, $validated): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if(
                $lockedUser->role === UserRole::Administrator,
                Response::HTTP_FORBIDDEN,
                'Administrator roles cannot be changed through this endpoint.',
            );

            $oldRole = $lockedUser->role;
            $newRole = UserRole::from($validated['role']);

            if ($oldRole === $newRole) {
                return;
            }

            $lockedUser->role = $newRole;
            $lockedUser->provider_verification_status = $newRole === UserRole::ServiceProvider
                ? ProviderVerificationStatus::Pending
                : ProviderVerificationStatus::NotApplicable;
            $lockedUser->save();
            if ($newRole === UserRole::ServiceProvider) {
                $lockedUser->providerProfile()->firstOrCreate([]);
            } elseif ($oldRole === UserRole::ServiceProvider) {
                $lockedUser->providerProfile()->update([
                    'is_available' => false,
                    'available_since' => null,
                ]);
                $lockedUser->services()->update(['is_active' => false]);
            }

            $this->audit($request, $lockedUser, 'user.role_changed', [
                'from' => $oldRole->value,
                'to' => $newRole->value,
            ]);
        });

        return response()->json(['data' => $this->userSummary($user->refresh())]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        abort_if($request->user()->is($user), Response::HTTP_FORBIDDEN, 'Administrators cannot suspend their own account.');
        abort_if(
            $user->role === UserRole::Administrator,
            Response::HTTP_FORBIDDEN,
            'Administrator accounts cannot be suspended through this endpoint.',
        );

        DB::transaction(function () use ($request, $user, $validated): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if(
                $lockedUser->role === UserRole::Administrator,
                Response::HTTP_FORBIDDEN,
                'Administrator accounts cannot be suspended through this endpoint.',
            );

            if ($lockedUser->isSuspended()) {
                return;
            }

            $lockedUser->forceFill([
                'suspended_at' => now(),
                'suspension_reason' => $validated['reason'],
            ])->save();
            if ($lockedUser->role === UserRole::ServiceProvider) {
                $lockedUser->providerProfile()->update([
                    'is_available' => false,
                    'available_since' => null,
                ]);
            }
            $lockedUser->tokens()->delete();

            $this->audit($request, $lockedUser, 'user.suspended', [
                'reason' => $validated['reason'],
            ]);
        });

        return response()->json(['data' => $this->userSummary($user->refresh())]);
    }

    public function unsuspend(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        abort_if(
            $user->role === UserRole::Administrator,
            Response::HTTP_FORBIDDEN,
            'Administrator accounts cannot be unsuspended through this endpoint.',
        );

        DB::transaction(function () use ($request, $user, $validated): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if(
                $lockedUser->role === UserRole::Administrator,
                Response::HTTP_FORBIDDEN,
                'Administrator accounts cannot be unsuspended through this endpoint.',
            );

            if (! $lockedUser->isSuspended()) {
                return;
            }

            $lockedUser->forceFill([
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();

            $this->audit($request, $lockedUser, 'user.unsuspended', [
                'reason' => $validated['reason'],
            ]);
        });

        return response()->json(['data' => $this->userSummary($user->refresh())]);
    }

    private function audit(Request $request, User $target, string $action, array $context = []): void
    {
        $actor = $request->user();

        AdministrativeAuditLog::query()->create([
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->role->value,
            'target_user_id' => $target->id,
            'target_role' => $target->role->value,
            'action' => $action,
            'context' => $context,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function userSummary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'provider_verification_status' => $user->provider_verification_status->value,
            'suspended_at' => $user->suspended_at?->toIso8601String(),
            'suspension_reason' => $user->suspension_reason,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
