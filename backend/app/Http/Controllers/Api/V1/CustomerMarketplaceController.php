<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\ProviderOfferStatus;
use App\Enums\ProviderVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\CreateBookingRequest;
use App\Http\Requests\Api\V1\Customer\CreateReviewRequest;
use App\Http\Resources\Api\V1\BookingResource;
use App\Jobs\MatchBookingJob;
use App\Models\Booking;
use App\Models\BookingStatusEvent;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\UserPushToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerMarketplaceController extends Controller
{
    public function categories(): JsonResponse
    {
        return response()->json(['data' => ServiceCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()]);
    }

    public function services(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:service_categories,id'],
            'latitude' => ['sometimes', 'required_with:longitude,radius_km', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required_with:latitude,radius_km', 'numeric', 'between:-180,180'],
            'radius_km' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $this->publicServices()->with([
            'category',
            'provider' => function (BelongsTo $provider): void {
                $provider->with('providerProfile')
                    ->withCount('reviewsReceived')
                    ->withAvg('reviewsReceived', 'rating');
            },
        ]);
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('category', fn (Builder $category) => $category
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%"))
                    ->orWhereHas('provider', fn (Builder $provider) => $provider->where('name', 'like', "%{$search}%"));
            });
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        $services = $query->orderBy('name')->limit(500)->get();
        $hasCoordinates = isset($filters['latitude'], $filters['longitude']);
        if ($hasCoordinates) {
            $radius = (float) ($filters['radius_km'] ?? 25);
            $services = $services->map(function (Service $service) use ($filters): Service {
                $profile = $service->provider->providerProfile;
                $service->setAttribute('distance_km', $profile?->latitude === null || $profile?->longitude === null
                    ? null
                    : round($this->distanceKm(
                        (float) $filters['latitude'],
                        (float) $filters['longitude'],
                        (float) $profile->latitude,
                        (float) $profile->longitude,
                    ), 2));

                return $service;
            })->filter(fn (Service $service) => $service->distance_km !== null && $service->distance_km <= $radius)
                ->sortBy('distance_km')->values();
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $services->forPage($page, $perPage)->values(),
            $services->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return response()->json($paginator->through(
            fn (Service $service) => $this->serviceSummary($service),
        ));
    }

    public function showService(Service $service): JsonResponse
    {
        abort_unless($this->isDiscoverable($service), 404);
        $service->load([
            'category',
            'provider' => function (BelongsTo $provider): void {
                $provider->with('providerProfile')
                    ->withCount('reviewsReceived')
                    ->withAvg('reviewsReceived', 'rating');
            },
        ]);

        return response()->json(['data' => $this->serviceSummary($service, true)]);
    }

    public function showProvider(User $provider): JsonResponse
    {
        abort_unless($provider->role?->value === 'service_provider'
            && $provider->provider_verification_status === ProviderVerificationStatus::Verified
            && ! $provider->isSuspended(), 404);

        $provider->load([
            'providerProfile.portfolioItems',
            'services' => fn (HasMany $query) => $query
                ->where('is_active', true)
                ->whereHas('category', fn (Builder $category) => $category->where('is_active', true))
                ->with([
                    'category',
                    'provider' => function (BelongsTo $providerQuery): void {
                        $providerQuery->with('providerProfile')
                            ->withCount('reviewsReceived')
                            ->withAvg('reviewsReceived', 'rating');
                    },
                ])->orderBy('name'),
        ])->loadCount('reviewsReceived')->loadAvg('reviewsReceived', 'rating');
        $reviews = $provider->reviewsReceived()->with('customer:id,name')->latest()->paginate(10);

        return response()->json([
            'data' => $this->providerSummary($provider, true),
            'services' => $provider->services->map(fn (Service $service) => $this->serviceSummary($service)),
            'reviews' => $reviews,
            'rating_average' => round((float) $provider->reviews_received_avg_rating, 2),
            'rating_count' => $provider->reviews_received_count,
        ]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $bookings = $request->user()->customerBookings()
            ->with(['service.category', 'provider.providerProfile', 'review'])
            ->latest()->paginate(15);
        $bookings->through(fn (Booking $booking) => $this->withMatchingSummary($booking));

        return BookingResource::collection($bookings)->response();
    }

    public function createBooking(CreateBookingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $service = Service::query()->with('provider')->findOrFail($validated['service_id']);
        abort_unless($this->isDiscoverable($service), 422, 'This service is not currently available.');

        $booking = DB::transaction(function () use ($request, $validated): Booking {
            $booking = $request->user()->customerBookings()->create([
                'service_id' => $validated['service_id'],
                'requested_address' => $validated['address'],
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'scheduled_at' => $validated['scheduled_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'provider_id' => null,
                'status' => BookingStatus::PendingMatching,
                'matching_attempt' => 1,
                'matching_started_at' => now(),
            ]);
            BookingStatusEvent::query()->create([
                'booking_id' => $booking->id,
                'actor_user_id' => $request->user()->id,
                'to_status' => BookingStatus::PendingMatching->value,
                'created_at' => now(),
            ]);
            $booking->matchingAttempts()->create([
                'attempt' => 1,
                'status' => 'searching',
                'started_at' => now(),
            ]);
            MatchBookingJob::dispatch($booking->id)->afterCommit();

            return $booking;
        });
        $booking = $this->withMatchingSummary(
            $booking->fresh()->load(['service.category', 'provider.providerProfile', 'review']),
        );

        return (new BookingResource($booking))->response()->setStatusCode(201);
    }

    public function showBooking(Request $request, int $booking): JsonResponse
    {
        $booking = $request->user()->customerBookings()
            ->with(['service.category', 'provider.providerProfile', 'review'])
            ->findOrFail($booking);

        return (new BookingResource($this->withMatchingSummary($booking)))->response();
    }

    public function cancelBooking(Request $request, int $booking): JsonResponse
    {
        $booking = DB::transaction(function () use ($request, $booking): ?Booking {
            $lockedBooking = $request->user()->customerBookings()->lockForUpdate()->findOrFail($booking);
            if (! in_array($lockedBooking->status, [
                BookingStatus::PendingMatching,
                BookingStatus::Offered,
                BookingStatus::Matched,
                BookingStatus::Accepted,
                BookingStatus::EnRoute,
                BookingStatus::NoProviderAvailable,
            ], true)) {
                return null;
            }

            $previous = $lockedBooking->status;
            $provider = $lockedBooking->provider;
            $lockedBooking->update(['status' => BookingStatus::Cancelled]);
            $lockedBooking->matchingAttempts()
                ->where('attempt', $lockedBooking->matching_attempt)
                ->whereIn('status', ['searching', 'offered', 'accepted'])
                ->update([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            BookingStatusEvent::query()->create([
                'booking_id' => $lockedBooking->id,
                'actor_user_id' => $request->user()->id,
                'from_status' => $previous->value,
                'to_status' => BookingStatus::Cancelled->value,
                'created_at' => now(),
            ]);
            $lockedBooking->providerOffers()
                ->where('status', ProviderOfferStatus::Pending)
                ->update([
                    'status' => ProviderOfferStatus::Cancelled->value,
                    'responded_at' => now(),
                    'updated_at' => now(),
                ]);
            if ($provider && $lockedBooking->provider_was_available) {
                $profile = $provider->providerProfile;
                if ($profile
                    && $provider->provider_verification_status === ProviderVerificationStatus::Verified
                    && $provider->hasVerifiedEmail()
                    && ! $provider->isSuspended()
                    && filled($profile->identity_document_path)
                    && $provider->services()->where('is_active', true)->exists()) {
                    $profile->update(['is_available' => true, 'available_since' => now()]);
                }
            }

            return $lockedBooking;
        });
        if (! $booking) {
            return response()->json([
                'message' => 'This booking can no longer be cancelled.',
                'code' => 'booking_cancellation_not_allowed',
            ], 409);
        }

        $booking = $this->withMatchingSummary(
            $booking->fresh()->load(['service.category', 'provider.providerProfile', 'review']),
        );

        return (new BookingResource($booking))->response();
    }

    public function retryMatching(Request $request, int $booking): JsonResponse
    {
        $booking = DB::transaction(function () use ($request, $booking): ?Booking {
            $lockedBooking = $request->user()->customerBookings()->lockForUpdate()->findOrFail($booking);
            if ($lockedBooking->status !== BookingStatus::NoProviderAvailable) {
                return null;
            }
            $maxAttempts = max(0, (int) config('neybor.matching.max_attempts', 3));
            if ($maxAttempts > 0 && $lockedBooking->matching_attempt >= $maxAttempts) {
                return null;
            }

            $previousAttempt = $lockedBooking->matching_attempt;
            $lockedBooking->update([
                'status' => BookingStatus::PendingMatching,
                'matching_attempt' => $previousAttempt + 1,
                'matching_started_at' => now(),
                'matched_at' => null,
            ]);
            BookingStatusEvent::query()->create([
                'booking_id' => $lockedBooking->id,
                'actor_user_id' => $request->user()->id,
                'from_status' => BookingStatus::NoProviderAvailable->value,
                'to_status' => BookingStatus::PendingMatching->value,
                'created_at' => now(),
            ]);
            $lockedBooking->matchingAttempts()->create([
                'attempt' => $previousAttempt + 1,
                'status' => 'searching',
                'started_at' => now(),
            ]);

            return $lockedBooking;
        });
        if (! $booking) {
            return response()->json([
                'message' => 'This request cannot be retried right now.',
                'code' => 'matching_retry_not_available',
            ], 409);
        }

        MatchBookingJob::dispatch($booking->id)->afterCommit();

        $booking = $this->withMatchingSummary(
            $booking->fresh()->load(['service.category', 'provider.providerProfile', 'review']),
        );

        return (new BookingResource($booking))->response();
    }

    public function completeBooking(Request $request, int $booking): JsonResponse
    {
        $booking = $request->user()->customerBookings()->findOrFail($booking);

        return response()->json([
            'message' => $booking->status === BookingStatus::Completed
                ? 'Your provider has already marked this service complete.'
                : 'Only the assigned provider can mark this service complete.',
            'code' => 'provider_completion_required',
        ], 409);
    }

    public function reviewBooking(CreateReviewRequest $request, int $booking): JsonResponse
    {
        $validated = $request->validated();
        $booking = $request->user()->customerBookings()->findOrFail($booking);
        if ($booking->status !== BookingStatus::Completed || $booking->provider_id === null) {
            return response()->json([
                'message' => 'Only completed bookings can be reviewed.',
                'code' => 'booking_not_reviewable',
            ], 409);
        }
        if ($booking->review()->exists()) {
            return response()->json([
                'message' => 'A review has already been submitted for this booking.',
                'code' => 'review_already_submitted',
            ], 409);
        }

        $review = $booking->review()->create([
            ...$validated,
            'customer_id' => $request->user()->id,
            'provider_id' => $booking->provider_id,
        ]);

        return response()->json(['data' => $review], 201);
    }

    public function savedLocations(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->savedLocations()->orderByDesc('is_default')->latest()->get()]);
    }

    public function createSavedLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        $location = DB::transaction(function () use ($request, $validated) {
            if (($validated['is_default'] ?? false) || ! $request->user()->savedLocations()->exists()) {
                $request->user()->savedLocations()->update(['is_default' => false]);
                $validated['is_default'] = true;
            }

            return $request->user()->savedLocations()->create($validated);
        });

        return response()->json(['data' => $location], 201);
    }

    public function updateSavedLocation(Request $request, int $location): JsonResponse
    {
        $location = $request->user()->savedLocations()->findOrFail($location);
        $validated = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:80'],
            'address' => ['sometimes', 'required', 'string', 'max:500'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        DB::transaction(function () use ($request, $location, &$validated): void {
            if (($validated['is_default'] ?? false) === true) {
                $request->user()->savedLocations()->update(['is_default' => false]);
            } elseif (($validated['is_default'] ?? null) === false && $location->is_default) {
                $replacement = $request->user()->savedLocations()
                    ->where('id', '!=', $location->id)->orderBy('id')->first();
                if ($replacement) {
                    $replacement->update(['is_default' => true]);
                } else {
                    $validated['is_default'] = true;
                }
            }
            $location->update($validated);
        });

        return response()->json(['data' => $location->fresh()]);
    }

    public function deleteSavedLocation(Request $request, int $location): JsonResponse
    {
        DB::transaction(function () use ($request, $location): void {
            $location = $request->user()->savedLocations()->findOrFail($location);
            $wasDefault = $location->is_default;
            $location->delete();
            if ($wasDefault) {
                $request->user()->savedLocations()->orderBy('id')->first()?->update(['is_default' => true]);
            }
        });

        return response()->json([], 204);
    }

    public function notifications(Request $request): JsonResponse
    {
        return response()->json($request->user()->notifications()->latest()->paginate(20));
    }

    public function readNotification(Request $request, string $notification): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($notification);
        $notification->markAsRead();

        return response()->json(['data' => $notification]);
    }

    public function registerPushToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'device_id' => ['nullable', 'string', 'max:255'],
        ]);
        $existing = UserPushToken::query()->where('token', $validated['token'])->first();
        if ($existing && $existing->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'This push token is already registered.',
                'code' => 'push_token_already_registered',
            ], 409);
        }
        $token = $request->user()->pushTokens()->updateOrCreate(
            ['token' => $validated['token']],
            [...$validated, 'last_used_at' => now()],
        );

        return response()->json(['data' => $token], $existing ? 200 : 201);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        $request->user()->update($validated);

        return response()->json(['data' => [
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'email' => $request->user()->email,
            'phone' => $request->user()->phone,
        ]]);
    }

    public function supportTickets(Request $request): JsonResponse
    {
        return response()->json($request->user()->supportTickets()->latest()->paginate(15));
    }

    public function createSupportTicket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:5000'],
        ]);
        $ticket = $request->user()->supportTickets()->create($validated);

        return response()->json(['data' => $ticket], 201);
    }

    private function publicServices(): Builder
    {
        return Service::query()->where('is_active', true)
            ->whereHas('category', fn (Builder $query) => $query->where('is_active', true))
            ->whereHas('provider', fn (Builder $query) => $query
                ->where('role', 'service_provider')
                ->where('provider_verification_status', ProviderVerificationStatus::Verified->value)
                ->whereNotNull('email_verified_at')
                ->whereNull('suspended_at'));
    }

    private function isDiscoverable(Service $service): bool
    {
        return (bool) $this->publicServices()->whereKey($service->getKey())->exists();
    }

    private function serviceSummary(Service $service, bool $includeReviews = false): array
    {
        $provider = $service->provider;
        $summary = [
            'id' => $service->id,
            'name' => $service->name,
            'description' => $service->description,
            'base_price' => $service->base_price,
            'price_unit' => $service->price_unit,
            'category' => $service->category,
            'provider' => $provider ? $this->providerSummary($provider) : null,
        ];
        if (array_key_exists('distance_km', $service->getAttributes())) {
            $summary['distance_km'] = $service->distance_km;
        }
        if ($includeReviews && $provider) {
            $summary['rating_average'] = round((float) $provider->reviews_received_avg_rating, 2);
            $summary['rating_count'] = $provider->reviews_received_count;
            $summary['reviews'] = $provider->reviewsReceived()
                ->with('customer:id,name')->latest()->limit(10)->get();
        }

        return $summary;
    }

    private function withMatchingSummary(Booking $booking): Booking
    {
        $booking->loadMissing(['service.category', 'provider.providerProfile', 'review', 'statusEvents']);
        $currentOffers = $booking->providerOffers()
            ->where('matching_attempt', $booking->matching_attempt);
        $offersSent = (clone $currentOffers)->count();
        $activeOffer = (clone $currentOffers)
            ->where('status', ProviderOfferStatus::Pending)
            ->where('expires_at', '>', now())
            ->latest('offered_at')
            ->first(['expires_at']);

        $stage = match ($booking->status) {
            BookingStatus::Matched, BookingStatus::Accepted,
            BookingStatus::EnRoute, BookingStatus::Arrived,
            BookingStatus::InProgress, BookingStatus::Completed => 'confirmed',
            BookingStatus::NoProviderAvailable => 'no_providers_available',
            BookingStatus::Offered => 'awaiting_provider',
            BookingStatus::PendingMatching => $activeOffer ? 'awaiting_provider' : 'searching',
            default => 'closed',
        };
        $booking->setAttribute('matching', [
            'attempt' => $booking->matching_attempt,
            'stage' => $stage,
            'offers_sent' => $offersSent,
            'offer_expires_at' => $activeOffer?->expires_at,
        ]);
        $booking->setAttribute('events', $booking->statusEvents->map(fn ($event): array => [
            'status' => $event->to_status,
            'note' => $event->reason,
            'created_at' => $event->created_at,
        ])->values());
        $booking->setAttribute('can_cancel', in_array($booking->status, [
            BookingStatus::PendingMatching,
            BookingStatus::Offered,
            BookingStatus::Matched,
            BookingStatus::Accepted,
            BookingStatus::EnRoute,
            BookingStatus::NoProviderAvailable,
        ], true));

        return $booking;
    }

    private function providerSummary(User $provider, bool $includeBio = false): array
    {
        $profile = $provider->providerProfile;
        $data = [
            'id' => $provider->id,
            'name' => $provider->name,
            'avatar_url' => $profile?->avatar_url,
            'business_name' => $profile?->business_name,
            'latitude' => $profile?->latitude === null ? null : round((float) $profile->latitude, 3),
            'longitude' => $profile?->longitude === null ? null : round((float) $profile->longitude, 3),
            'is_available' => $profile?->is_available,
            'is_verified' => $provider->provider_verification_status === ProviderVerificationStatus::Verified
                && ! $provider->isSuspended(),
            'rating_average' => $provider->reviews_received_avg_rating === null
                ? null
                : round((float) $provider->reviews_received_avg_rating, 2),
            'rating_count' => (int) ($provider->reviews_received_count ?? 0),
        ];
        if ($includeBio) {
            $data['bio'] = $profile?->bio;
            $data['skills'] = $profile?->skills ?? [];
            $data['portfolio'] = $profile?->portfolioItems?->map(fn ($item): array => [
                'id' => $item->id,
                'image_url' => $item->image_path,
                'caption' => $item->caption,
            ])->values() ?? [];
        }

        return $data;
    }

    private function distanceKm(float $latitude, float $longitude, float $otherLatitude, float $otherLongitude): float
    {
        $earthRadiusKm = 6371;
        $latitudeDelta = deg2rad($otherLatitude - $latitude);
        $longitudeDelta = deg2rad($otherLongitude - $longitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($otherLatitude)) * sin($longitudeDelta / 2) ** 2;

        return $earthRadiusKm * 2 * asin(min(1, sqrt($a)));
    }
}
