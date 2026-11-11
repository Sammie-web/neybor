<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\ProviderVerificationStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderAvailabilityController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
            'latitude' => ['required_if:is_available,true', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:is_available,true', 'nullable', 'numeric', 'between:-180,180'],
            'coverage_radius_km' => ['sometimes', 'numeric', 'between:1,200'],
        ]);
        $provider = $request->user();
        if ($validated['is_available']
            && $provider->provider_verification_status !== ProviderVerificationStatus::Verified) {
            return response()->json([
                'message' => 'Provider verification is required before accepting matching offers.',
                'code' => 'provider_verification_required',
            ], 403);
        }
        if ($validated['is_available'] && ! $provider->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Verify your email address before going online.',
                'code' => 'email_verification_required',
            ], 403);
        }
        if ($validated['is_available'] && ! $provider->services()->where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'Publish an active service before becoming available for requests.',
                'code' => 'provider_service_required',
            ], 409);
        }
        $provider->providerProfile()->firstOrCreate([]);
        $result = DB::transaction(function () use ($provider, $validated): array {
            $profile = $provider->providerProfile()->lockForUpdate()->firstOrFail();
            if ($validated['is_available'] && ! $profile->identity_document_path) {
                return ['identity_required' => true];
            }
            if ($validated['is_available'] && $provider->providerBookings()
                ->whereIn('status', [
                    BookingStatus::Matched,
                    BookingStatus::Accepted,
                    BookingStatus::EnRoute,
                    BookingStatus::Arrived,
                    BookingStatus::InProgress,
                ])->exists()) {
                return ['active_booking' => true];
            }

            $profile->update([
                ...$validated,
                'available_since' => $validated['is_available'] ? now() : null,
            ]);

            return ['profile' => $profile];
        });
        if (isset($result['identity_required'])) {
            return response()->json([
                'message' => 'Submit an identity document before going online.',
                'code' => 'identity_verification_required',
            ], 403);
        }
        if (isset($result['active_booking'])) {
            return response()->json([
                'message' => 'Finish or cancel your active booking before becoming available for new requests.',
                'code' => 'provider_has_active_booking',
            ], 409);
        }
        $profile = $result['profile'];

        return response()->json([
            'data' => [
                'is_available' => $profile->is_available,
                'latitude' => $profile->latitude,
                'longitude' => $profile->longitude,
                'coverage_radius_km' => (float) $profile->coverage_radius_km,
            ],
        ]);
    }
}
