<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WaitlistRegistration;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WaitlistController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(['customer', 'provider'])],
            'phone' => ['nullable', 'string', 'max:32'],
            'business_name' => ['required_if:role,provider', 'nullable', 'string', 'max:160'],
            'service_interest' => ['nullable', 'string', 'max:120'],
        ]);
        $email = Str::lower(trim($validated['email']));
        $existing = WaitlistRegistration::query()
            ->where('email', $email)
            ->where('role', $validated['role'])
            ->exists();
        if ($existing) {
            return response()->json([
                'message' => 'You are already on this waitlist.',
                'code' => 'already_registered',
            ]);
        }

        try {
            $registration = WaitlistRegistration::query()->create([
                ...$validated,
                'email' => $email,
                'source' => 'prelaunch_website',
                'created_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! WaitlistRegistration::query()->where('email', $email)->where('role', $validated['role'])->exists()) {
                throw $exception;
            }

            return response()->json([
                'message' => 'You are already on this waitlist.',
                'code' => 'already_registered',
            ]);
        }

        return response()->json([
            'message' => 'You are on the list. We will be in touch.',
            'data' => ['id' => $registration->id, 'role' => $registration->role],
        ], 201);
    }
}
