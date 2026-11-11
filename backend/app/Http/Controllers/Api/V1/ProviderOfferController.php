<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\ProviderOfferStatus;
use App\Enums\ProviderVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\RespondToProviderOfferRequest;
use App\Http\Resources\Api\V1\BookingResource;
use App\Jobs\MatchBookingJob;
use App\Models\Booking;
use App\Models\BookingMatchingAttempt;
use App\Models\BookingStatusEvent;
use App\Models\ProviderProfile;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProviderOfferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $offers = $request->user()->providerOffers()
            ->with(['service.category', 'booking.customer:id,name'])
            ->where('status', ProviderOfferStatus::Pending)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->paginate(20);
        $offers->getCollection()->transform(fn ($offer): array => [
            'id' => $offer->id,
            'expires_at' => $offer->expires_at,
            'distance_km' => (float) $offer->distance_km,
            'service' => $offer->service,
            'booking' => [
                'id' => $offer->booking->id,
                'scheduled_at' => $offer->booking->scheduled_at,
                'notes' => $offer->booking->notes,
                'location_preview' => 'Within '.number_format((float) $offer->distance_km, 1).' km of your service location',
                'customer_label' => 'Neybor customer',
            ],
        ]);

        return response()->json($offers);
    }

    public function respond(RespondToProviderOfferRequest $request, int $offer): JsonResponse
    {
        $validated = $request->validated();
        $offerRecord = $request->user()->providerOffers()->findOrFail($offer);
        $result = DB::transaction(function () use ($request, $offerRecord, $validated): array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($offerRecord->booking_id);
            $provider = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $providerOffer = $request->user()->providerOffers()
                ->lockForUpdate()->findOrFail($offerRecord->id);

            if ($providerOffer->status !== ProviderOfferStatus::Pending
                || $providerOffer->matching_attempt !== $booking->matching_attempt
                || ! in_array($booking->status, [
                    BookingStatus::PendingMatching,
                    BookingStatus::Offered,
                ], true)) {
                return ['conflict' => 'This offer is no longer active.'];
            }

            $providerProfile = ProviderProfile::query()
                ->where('user_id', $provider->id)
                ->lockForUpdate()
                ->first();
            if ($providerOffer->expires_at->isPast()) {
                $providerOffer->update([
                    'status' => ProviderOfferStatus::Expired,
                    'responded_at' => now(),
                ]);

                return ['expired' => true, 'booking_id' => $booking->id];
            }

            if ($validated['decision'] === 'reject') {
                $providerOffer->update([
                    'status' => ProviderOfferStatus::Rejected,
                    'responded_at' => now(),
                ]);

                return ['rejected' => true, 'booking_id' => $booking->id];
            }

            if (! $providerProfile?->is_available
                || ! $provider->hasVerifiedEmail()
                || ! $providerProfile->identity_document_path
                || $provider->isSuspended()
                || $provider->provider_verification_status !== ProviderVerificationStatus::Verified
                || ! $provider->services()
                    ->whereKey($providerOffer->service_id)
                    ->where('is_active', true)
                    ->exists()) {
                $providerOffer->update([
                    'status' => ProviderOfferStatus::Rejected,
                    'responded_at' => now(),
                ]);

                return ['rejected' => true, 'booking_id' => $booking->id];
            }

            $hasActiveBooking = $provider->providerBookings()
                ->whereIn('status', [
                    BookingStatus::Matched,
                    BookingStatus::Accepted,
                    BookingStatus::EnRoute,
                    BookingStatus::Arrived,
                    BookingStatus::InProgress,
                ])
                ->exists();
            $service = Service::query()
                ->where('provider_id', $provider->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->find($providerOffer->service_id);
            $requestedService = $booking->service;
            $serviceCompatibility = config('neybor.matching.service_compatibility');
            if (! in_array($serviceCompatibility, ['category', 'exact'], true)) {
                throw new InvalidArgumentException('Unsupported matching service compatibility mode.');
            }
            $serviceCompatible = $service && $requestedService && match ($serviceCompatibility) {
                'exact' => $service->id === $requestedService->id,
                'category' => $service->category_id === $requestedService->category_id
                    && $service->category()->where('is_active', true)->exists(),
            };
            $distance = $providerProfile->latitude !== null && $providerProfile->longitude !== null
                && $booking->latitude !== null && $booking->longitude !== null
                ? $this->distanceKm(
                    (float) $booking->latitude,
                    (float) $booking->longitude,
                    (float) $providerProfile->latitude,
                    (float) $providerProfile->longitude,
                )
                : INF;
            $maxDistance = min(
                (float) $providerProfile->coverage_radius_km,
                (float) config('neybor.matching.max_distance_km', 50),
            );
            if ($hasActiveBooking || ! $serviceCompatible || $distance > $maxDistance) {
                $providerOffer->update([
                    'status' => ProviderOfferStatus::Rejected,
                    'responded_at' => now(),
                ]);

                return ['rejected' => true, 'booking_id' => $booking->id];
            }

            $providerWasAvailable = (bool) $providerProfile->is_available;
            $previousStatus = $booking->status;
            $providerOffer->update([
                'status' => ProviderOfferStatus::Accepted,
                'responded_at' => now(),
            ]);
            $booking->providerOffers()
                ->where('id', '!=', $providerOffer->id)
                ->where('status', ProviderOfferStatus::Pending)
                ->update([
                    'status' => ProviderOfferStatus::Cancelled->value,
                    'responded_at' => now(),
                    'updated_at' => now(),
                ]);
            $booking->update([
                'service_id' => $providerOffer->service_id,
                'provider_id' => $provider->id,
                'status' => BookingStatus::Matched,
                'matched_at' => now(),
                'provider_was_available' => $providerWasAvailable,
            ]);
            $provider->providerProfile()->update([
                'is_available' => false,
                'available_since' => null,
            ]);
            BookingStatusEvent::query()->create([
                'booking_id' => $booking->id,
                'actor_user_id' => $provider->id,
                'from_status' => $previousStatus->value,
                'to_status' => BookingStatus::Matched->value,
                'created_at' => now(),
            ]);
            BookingMatchingAttempt::query()
                ->where('booking_id', $booking->id)
                ->where('attempt', $booking->matching_attempt)
                ->update([
                    'status' => 'accepted',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            return ['accepted' => true, 'booking_id' => $booking->id];
        });

        if (isset($result['conflict'])) {
            return response()->json([
                'message' => $result['conflict'],
                'code' => 'provider_offer_not_active',
            ], 409);
        }

        if (isset($result['expired']) || isset($result['rejected'])) {
            MatchBookingJob::dispatch($result['booking_id'])->afterCommit();

            return response()->json([
                'message' => isset($result['expired'])
                    ? 'This offer expired and the request is being matched again.'
                    : 'Offer declined. The request is being offered to another professional.',
            ]);
        }

        $booking = Booking::query()
            ->with(['service.category', 'provider.providerProfile', 'customer'])
            ->findOrFail($result['booking_id']);

        return (new BookingResource($booking))
            ->additional(['message' => 'Booking assigned to you.'])
            ->response();
    }

    private function distanceKm(float $latitude, float $longitude, float $otherLatitude, float $otherLongitude): float
    {
        $earthRadius = 6371;
        $latitudeDelta = deg2rad($otherLatitude - $latitude);
        $longitudeDelta = deg2rad($otherLongitude - $longitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($otherLatitude))
            * sin($longitudeDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
