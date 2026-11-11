<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\ProviderOfferStatus;
use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Jobs\MatchBookingJob;
use App\Models\Booking;
use App\Models\BookingMatchingAttempt;
use App\Models\BookingProviderExclusion;
use App\Models\BookingStatusEvent;
use App\Models\ProviderOffer;
use App\Models\Service;
use App\Notifications\ProviderOfferReceived;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BookingMatcher
{
    public function offerNext(int $bookingId): void
    {
        DB::transaction(function () use ($bookingId): void {
            $booking = Booking::query()->lockForUpdate()->find($bookingId);
            if (! $booking || ! in_array($booking->status, [
                BookingStatus::PendingMatching,
                BookingStatus::Offered,
            ], true)) {
                return;
            }

            $now = now();
            $attempt = BookingMatchingAttempt::query()->firstOrCreate(
                ['booking_id' => $booking->id, 'attempt' => $booking->matching_attempt],
                ['status' => 'searching', 'started_at' => $now],
            );
            $booking->providerOffers()
                ->where('matching_attempt', $booking->matching_attempt)
                ->where('status', ProviderOfferStatus::Pending)
                ->where('expires_at', '<=', $now)
                ->update([
                    'status' => ProviderOfferStatus::Expired->value,
                    'responded_at' => $now,
                    'updated_at' => $now,
                ]);

            $pendingOffers = $booking->providerOffers()
                ->where('matching_attempt', $booking->matching_attempt)
                ->where('status', ProviderOfferStatus::Pending)
                ->where('expires_at', '>', $now)
                ->count();
            $offerPolicy = config('neybor.matching.offer_policy');
            $batchSize = match ($offerPolicy['mode']) {
                'sequential' => 1,
                'parallel' => min(10, max(1, (int) $offerPolicy['batch_size'])),
                default => throw new InvalidArgumentException('Unsupported matching offer policy.'),
            };
            $slots = $batchSize - $pendingOffers;
            if ($slots <= 0) {
                return;
            }

            $requestedService = $booking->service()->first();
            if (! $requestedService || $booking->latitude === null || $booking->longitude === null) {
                $this->markUnavailable($booking, $attempt, 'The request has no usable service or location.');

                return;
            }

            $previouslyContacted = $booking->providerOffers()
                ->where('matching_attempt', $booking->matching_attempt)
                ->pluck('provider_id')
                ->all();

            $serviceQuery = Service::query()
                ->where('category_id', $requestedService->category_id);
            $serviceCompatibility = config('neybor.matching.service_compatibility');
            if (! in_array($serviceCompatibility, ['category', 'exact'], true)) {
                throw new InvalidArgumentException('Unsupported matching service compatibility mode.');
            }
            $maxDistance = (float) config('neybor.matching.max_distance_km', 50);
            if (! is_finite($maxDistance) || $maxDistance <= 0) {
                throw new InvalidArgumentException('The matching maximum distance must be a positive number.');
            }
            $latitude = (float) $booking->latitude;
            $longitude = (float) $booking->longitude;
            $latitudeDelta = $maxDistance / 111.045;
            $longitudeScale = max(0.0001, abs(cos(deg2rad($latitude))));
            $longitudeDelta = min(180, $latitudeDelta / $longitudeScale);
            $latitudeBounds = [
                max(-90, $latitude - $latitudeDelta),
                min(90, $latitude + $latitudeDelta),
            ];
            $longitudeBounds = [
                $longitude - $longitudeDelta,
                $longitude + $longitudeDelta,
            ];
            if ($serviceCompatibility === 'exact') {
                $serviceQuery->whereKey($requestedService->id);
            }
            $candidates = $serviceQuery
                ->where('is_active', true)
                ->whereHas('category', fn (Builder $category) => $category->where('is_active', true))
                ->whereHas('provider', fn (Builder $provider) => $provider
                    ->where('role', UserRole::ServiceProvider->value)
                    ->where('provider_verification_status', ProviderVerificationStatus::Verified->value)
                    ->whereNotNull('email_verified_at')
                    ->whereNull('suspended_at')
                    ->whereHas('providerProfile', fn (Builder $profile) => $profile
                        ->where('is_available', true)
                        ->whereNotNull('identity_document_path')
                        ->whereNotNull('business_name')
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->whereBetween('latitude', $latitudeBounds)
                        ->when(
                            $longitudeBounds[0] >= -180 && $longitudeBounds[1] <= 180,
                            fn (Builder $profileQuery) => $profileQuery
                                ->whereBetween('longitude', $longitudeBounds),
                        )))
                ->with([
                    'provider.providerProfile',
                    'provider' => fn ($provider) => $provider
                        ->withCount('reviewsReceived')
                        ->withAvg('reviewsReceived', 'rating'),
                ])
                ->whereNotIn('provider_id', $previouslyContacted)
                ->whereNotIn('provider_id', BookingProviderExclusion::query()
                    ->where('booking_id', $booking->id)->select('provider_id'))
                ->whereDoesntHave('provider.providerOffers', fn (Builder $offer) => $offer
                    ->where('status', ProviderOfferStatus::Pending->value)
                    ->where('expires_at', '>', $now))
                ->whereDoesntHave('provider.providerBookings', fn (Builder $activeBooking) => $activeBooking
                    ->whereIn('status', [
                        BookingStatus::Matched->value,
                        BookingStatus::Accepted->value,
                        BookingStatus::EnRoute->value,
                        BookingStatus::Arrived->value,
                        BookingStatus::InProgress->value,
                    ]))
                ->get()
                ->map(function (Service $service) use ($booking, $requestedService, $maxDistance): array {
                    $profile = $service->provider->providerProfile;
                    $distance = $this->distanceKm(
                        (float) $booking->latitude,
                        (float) $booking->longitude,
                        (float) $profile->latitude,
                        (float) $profile->longitude,
                    );
                    $coverage = (float) $profile->coverage_radius_km;
                    if ($distance > min($coverage, $maxDistance)) {
                        return [];
                    }

                    $score = $this->candidateScore($requestedService, $service, $distance, $coverage, $maxDistance);

                    return [
                        'service' => $service,
                        'provider' => $service->provider,
                        'distance' => $distance,
                        'score' => $score,
                    ];
                })
                ->filter(fn (array $candidate): bool => $candidate !== [])
                ->sort(fn (array $first, array $second): int => ($first['score'] <=> $second['score'])
                    ?: ($first['distance'] <=> $second['distance'])
                    ?: ($first['provider']->id <=> $second['provider']->id))
                ->unique(fn (array $candidate): int => $candidate['provider']->id)
                ->values();

            $attempt->update([
                'candidate_count' => max(
                    (int) $attempt->candidate_count,
                    count($previouslyContacted) + $candidates->count(),
                ),
            ]);
            if ($candidates->isEmpty()) {
                if ($pendingOffers === 0) {
                    $this->markUnavailable($booking, $attempt, 'No eligible provider matched this request.');
                }

                return;
            }

            $ttl = max(15, (int) config('neybor.matching.offer_ttl_seconds', 90));
            $dispatched = [];
            foreach ($candidates->take($slots) as $candidate) {
                $offer = ProviderOffer::query()->create([
                    'booking_id' => $booking->id,
                    'provider_id' => $candidate['provider']->id,
                    'service_id' => $candidate['service']->id,
                    'matching_attempt' => $booking->matching_attempt,
                    'status' => ProviderOfferStatus::Pending,
                    'score' => $candidate['score'],
                    'distance_km' => $candidate['distance'],
                    'offered_at' => $now,
                    'expires_at' => $now->copy()->addSeconds($ttl),
                ]);
                $offer->load('service');
                $dispatched[] = [$candidate['provider'], $offer];
            }
            $previous = $booking->status;
            if ($previous !== BookingStatus::Offered) {
                $booking->update(['status' => BookingStatus::Offered]);
                BookingStatusEvent::query()->create([
                    'booking_id' => $booking->id,
                    'from_status' => $previous->value,
                    'to_status' => BookingStatus::Offered->value,
                    'created_at' => $now,
                ]);
            }
            $attempt->update([
                'status' => 'offered',
                'offers_dispatched' => $attempt->offers_dispatched + count($dispatched),
                'failure_reason' => null,
            ]);
            foreach ($dispatched as [$provider, $offer]) {
                DB::afterCommit(fn () => $provider->notify(new ProviderOfferReceived($offer)));
            }
        });
    }

    public function markFailed(int $bookingId, \Throwable $exception): void
    {
        DB::transaction(function () use ($bookingId, $exception): void {
            $booking = Booking::query()->lockForUpdate()->find($bookingId);
            if (! $booking || ! in_array($booking->status, [
                BookingStatus::PendingMatching,
                BookingStatus::Offered,
            ], true)) {
                return;
            }

            $attempt = BookingMatchingAttempt::query()->firstOrCreate(
                ['booking_id' => $booking->id, 'attempt' => $booking->matching_attempt],
                ['status' => 'searching', 'started_at' => now()],
            );
            $attempt->update([
                'status' => 'failed',
                'failure_reason' => Str::limit($exception->getMessage(), 2000),
                'completed_at' => now(),
            ]);
            if ($booking->providerOffers()
                ->where('matching_attempt', $booking->matching_attempt)
                ->where('status', ProviderOfferStatus::Pending)
                ->where('expires_at', '>', now())
                ->exists()) {
                return;
            }
            $this->markUnavailable(
                $booking,
                $attempt,
                'Matching could not be completed. Please retry.',
                'failed',
                Str::limit($exception->getMessage(), 2000),
            );
        });
    }

    public function expireOffers(): int
    {
        $expiredCount = 0;
        ProviderOffer::query()
            ->where('status', ProviderOfferStatus::Pending)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($offers) use (&$expiredCount): void {
                foreach ($offers as $offer) {
                    $shouldContinueMatching = DB::transaction(function () use ($offer): bool {
                        $booking = Booking::query()->lockForUpdate()->find($offer->booking_id);
                        $lockedOffer = ProviderOffer::query()->lockForUpdate()->find($offer->id);
                        if (! $lockedOffer || $lockedOffer->status !== ProviderOfferStatus::Pending
                            || $lockedOffer->expires_at->isFuture()) {
                            return false;
                        }
                        $lockedOffer->update([
                            'status' => ProviderOfferStatus::Expired,
                            'responded_at' => now(),
                        ]);

                        return $booking
                            && in_array($booking->status, [
                                BookingStatus::PendingMatching,
                                BookingStatus::Offered,
                            ], true)
                            && $booking->matching_attempt === $lockedOffer->matching_attempt;
                    });

                    $expiredCount++;
                    if ($shouldContinueMatching) {
                        MatchBookingJob::dispatch($offer->booking_id)->afterCommit();
                    }
                }
            });

        return $expiredCount;
    }

    private function markUnavailable(
        Booking $booking,
        BookingMatchingAttempt $attempt,
        string $reason,
        string $attemptStatus = 'no_match',
        ?string $failureReason = null,
    ): void {
        $previous = $booking->status;
        $booking->update([
            'status' => BookingStatus::NoProviderAvailable,
        ]);
        $attempt->update([
            'status' => $attemptStatus,
            'failure_reason' => $failureReason ?? $reason,
            'completed_at' => now(),
        ]);
        if ($previous !== BookingStatus::NoProviderAvailable) {
            BookingStatusEvent::query()->create([
                'booking_id' => $booking->id,
                'from_status' => $previous?->value,
                'to_status' => BookingStatus::NoProviderAvailable->value,
                'reason' => $reason,
                'created_at' => now(),
            ]);
        }
    }

    private function candidateScore(
        Service $requested,
        Service $candidate,
        float $distance,
        float $coverage,
        float $maxDistance,
    ): float {
        $ranking = config('neybor.matching.ranking');
        $weights = [];
        foreach ([
            'distance' => 'distance_weight',
            'relevance' => 'relevance_weight',
            'rating' => 'rating_weight',
            'availability' => 'availability_weight',
        ] as $criterion => $setting) {
            $weight = (float) $ranking[$setting];
            if (! is_finite($weight) || $weight < 0) {
                throw new InvalidArgumentException("The matching {$setting} must be a non-negative number.");
            }
            $weights[$criterion] = $weight;
        }
        $weightTotal = array_sum($weights);
        if (! is_finite($weightTotal)) {
            throw new InvalidArgumentException('The total matching ranking weight must be finite.');
        }
        if ($weightTotal === 0.0) {
            $weights['distance'] = 1;
            $weightTotal = 1;
        }

        $rating = min(5, max(0, (float) ($candidate->provider->reviews_received_avg_rating ?? 2.5)));
        $relevance = $this->serviceRelevance($requested->name, $candidate->name);
        $freshnessWindow = (int) $ranking['availability_freshness_minutes'];
        if ($freshnessWindow < 1) {
            throw new InvalidArgumentException('Availability freshness must be at least one minute.');
        }
        $availableSince = $candidate->provider->providerProfile->available_since
            ?? $candidate->provider->providerProfile->updated_at;
        $availabilityPenalty = min(
            1,
            max(0, now()->getTimestamp() - $availableSince->getTimestamp())
                / ($freshnessWindow * 60),
        );
        $maximumDistance = max(1, min($coverage, $maxDistance));

        return (
            $weights['distance'] * min(1, $distance / $maximumDistance)
            + $weights['relevance'] * (1 - $relevance)
            + $weights['rating'] * (1 - $rating / 5)
            + $weights['availability'] * $availabilityPenalty
        ) / max(0.000001, $weightTotal);
    }

    private function serviceRelevance(string $requested, string $candidate): float
    {
        $requested = Str::lower(trim($requested));
        $candidate = Str::lower(trim($candidate));
        if ($requested === $candidate) {
            return 1;
        }

        similar_text($requested, $candidate, $similarity);
        $requestedWords = array_unique(preg_split('/[^\p{L}\p{N}]+/u', $requested, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $candidateWords = array_unique(preg_split('/[^\p{L}\p{N}]+/u', $candidate, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $overlap = count(array_intersect($requestedWords, $candidateWords))
            / max(1, count($requestedWords));

        return min(1, max(0, ((float) $similarity / 100 * 0.6) + ($overlap * 0.4)));
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
