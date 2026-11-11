<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['sometimes', Rule::in([
                UserRole::Customer->value,
                UserRole::ServiceProvider->value,
                UserRole::Administrator->value,
            ])],
        ]);

        $role = ($validated['role'] ?? UserRole::Customer->value) === UserRole::ServiceProvider->value
            ? UserRole::ServiceProvider
            : UserRole::Customer;
        unset($validated['role']);
        $user = User::query()->create($validated);
        $user->forceFill([
            'role' => $role,
            'provider_verification_status' => $role === UserRole::ServiceProvider
                ? ProviderVerificationStatus::Pending
                : ProviderVerificationStatus::NotApplicable,
        ])->save();
        if ($role === UserRole::ServiceProvider) {
            $user->providerProfile()->create();
        }
        event(new Registered($user));

        return response()->json(['data' => $this->userData($user), 'token' => $user->createToken('mobile')->plainTextToken], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)
            || ! in_array($user->role, [
                UserRole::Customer,
                UserRole::ServiceProvider,
                UserRole::Administrator,
            ], true)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        if ($user->isSuspended()) {
            return response()->json(['message' => 'This account is suspended.', 'code' => 'account_suspended'], 403);
        }

        return response()->json(['data' => $this->userData($user), 'token' => $user->createToken('mobile')->plainTextToken]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userData($request->user())]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'If verification is required, an email has been sent.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $signedRequest = $request;
        if (is_string($request->input('verification_url'))) {
            $signedRequest = Request::create($request->input('verification_url'));
            $segments = explode('/', trim((string) parse_url($request->input('verification_url'), PHP_URL_PATH), '/'));
            $hash = array_pop($segments);
            $id = array_pop($segments);
        } else {
            $id = $request->query('id');
            $hash = $request->query('hash');
        }

        if (! URL::hasValidSignature($signedRequest) || ! $id || ! $hash) {
            return response()->json([
                'message' => 'The verification link is invalid or expired.',
                'code' => 'invalid_verification_link',
            ], 403);
        }

        $user = User::query()->findOrFail($id);
        if (! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            return response()->json([
                'message' => 'The verification link is invalid.',
                'code' => 'invalid_verification_link',
            ], 403);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => 'Email verified.', 'data' => $this->userData($user)]);
    }

    public function verifyLink(Request $request, int $id, string $hash): JsonResponse
    {
        if (! URL::hasValidSignature($request) || ! hash_equals(sha1((string) User::query()->findOrFail($id)->getEmailForVerification()), $hash)) {
            return response()->json([
                'message' => 'The verification link is invalid or expired.',
                'code' => 'invalid_verification_link',
            ], 403);
        }

        $user = User::query()->findOrFail($id);
        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => 'Email verified.', 'data' => $this->userData($user)]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        if (User::query()->where('email', $validated['email'])
            ->whereIn('role', [UserRole::Customer->value, UserRole::ServiceProvider->value])->exists()) {
            Password::sendResetLink($validated);
        }

        return response()->json(['message' => 'If the account exists, a reset token has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        if (! User::query()->where('email', $validated['email'])
            ->whereIn('role', [UserRole::Customer->value, UserRole::ServiceProvider->value])->exists()) {
            throw ValidationException::withMessages(['email' => [__('passwords.token')]]);
        }

        $status = Password::reset($validated, function (User $user, string $password): void {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'email_verified' => $user->hasVerifiedEmail(),
            'role' => $user->role->value,
            'provider_verification_status' => $user->provider_verification_status->value,
        ];
    }
}
