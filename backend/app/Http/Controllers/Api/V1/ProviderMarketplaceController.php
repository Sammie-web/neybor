<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\ProviderOfferStatus;
use App\Enums\ProviderVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\UpdateBookingStatusRequest;
use App\Http\Resources\Api\V1\BookingResource;
use App\Jobs\MatchBookingJob;
use App\Models\AdministrativeAuditLog;
use App\Models\BookingStatusEvent;
use App\Models\ProviderOffer;
use App\Models\ProviderPortfolioItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProviderMarketplaceController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $provider = $request->user();
        $profile = $provider->providerProfile()->firstOrCreate([]);
        $reviews = $provider->reviewsReceived();
        $completed = $provider->providerBookings()->where('status', BookingStatus::Completed);

        return response()->json(['data' => [
            'verification_status' => $provider->provider_verification_status->value,
            'email_verified' => $provider->hasVerifiedEmail(),
            'profile' => $this->profileData($provider),
            'stats' => [
                'incoming_requests' => $provider->providerOffers()
                    ->where('status', ProviderOfferStatus::Pending)->where('expires_at', '>', now())->count(),
                'active_bookings' => $provider->providerBookings()
                    ->whereIn('status', [
                        BookingStatus::Matched,
                        BookingStatus::Accepted,
                        BookingStatus::EnRoute,
                        BookingStatus::Arrived,
                        BookingStatus::InProgress,
                    ])->count(),
                'completed_jobs' => (clone $completed)->count(),
                'earnings' => (float) (clone $completed)->sum('quote_amount'),
                'rating_average' => round((float) $reviews->avg('rating'), 2),
                'review_count' => $reviews->count(),
            ],
            'offers' => $this->incomingOffers($provider->providerOffers()
                ->where('status', ProviderOfferStatus::Pending)->where('expires_at', '>', now())
                ->with(['booking.customer:id,name', 'service.category'])->orderBy('expires_at')->limit(10)->get()),
            'bookings' => $provider->providerBookings()
                ->with(['customer:id,name,phone', 'service.category', 'statusEvents'])
                ->whereIn('status', [
                    BookingStatus::Matched,
                    BookingStatus::Accepted,
                    BookingStatus::EnRoute,
                    BookingStatus::Arrived,
                    BookingStatus::InProgress,
                ])
                ->latest()->limit(10)->get(),
            'notifications' => $provider->notifications()->latest()->limit(10)->get(),
            'reviews' => $reviews->with('customer:id,name')->latest()->limit(5)->get(),
        ]]);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profileData($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:32'],
            'business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'skills' => ['sometimes', 'array', 'max:30'],
            'skills.*' => ['string', 'min:1', 'max:80'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'coverage_radius_km' => ['sometimes', 'numeric', 'between:1,200'],
        ]);
        $user = $request->user();
        $user->update(array_intersect_key($validated, array_flip(['name', 'phone'])));
        $profile = $user->providerProfile()->firstOrCreate([]);
        $profile->update(array_intersect_key($validated, array_flip([
            'business_name', 'bio', 'skills', 'latitude', 'longitude', 'coverage_radius_km',
        ])));

        return response()->json(['data' => $this->profileData($user->fresh())]);
    }

    public function uploadProfilePhoto(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $path = $request->file('photo')->storePublicly('providers/profile', 'public');
        if (! $path) {
            return response()->json([
                'message' => 'The profile photo could not be saved.',
                'code' => 'file_storage_failed',
            ], 500);
        }
        $profile = $request->user()->providerProfile()->firstOrCreate([]);
        if ($profile->avatar_url && str_contains($profile->avatar_url, '/storage/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', (string) parse_url($profile->avatar_url, PHP_URL_PATH)));
        }
        $profile->update(['avatar_url' => Storage::disk('public')->url($path)]);

        return response()->json(['data' => ['avatar_url' => $profile->avatar_url]]);
    }

    public function uploadIdentityDocument(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
            'document_type' => ['required', Rule::in(['national_id', 'drivers_license', 'voters_card', 'passport'])],
        ]);
        $profile = $request->user()->providerProfile()->firstOrCreate([]);
        $disk = config('neybor.identity_disk');
        $path = $request->file('document')->store('provider-identity', $disk);
        if (! $path) {
            return response()->json([
                'message' => 'The identity document could not be saved.',
                'code' => 'file_storage_failed',
            ], 500);
        }
        if ($profile->identity_document_path) {
            Storage::disk($disk)->delete($profile->identity_document_path);
        }
        $profile->update([
            'identity_document_path' => $path,
            'identity_document_type' => $validated['document_type'],
            'identity_submitted_at' => now(),
        ]);
        if ($request->user()->provider_verification_status === ProviderVerificationStatus::Verified) {
            $request->user()->forceFill(['provider_verification_status' => ProviderVerificationStatus::Pending])->save();
            $profile->update(['is_available' => false, 'available_since' => null]);
        }

        return response()->json(['data' => [
            'identity_document_type' => $profile->identity_document_type,
            'identity_submitted_at' => $profile->identity_submitted_at,
        ]], 201);
    }

    public function identityDocument(Request $request, int $user): StreamedResponse
    {
        $provider = User::query()->where('role', 'service_provider')->findOrFail($user);
        $path = $provider->providerProfile?->identity_document_path;
        $disk = config('neybor.identity_disk');
        abort_unless($path && Storage::disk($disk)->exists($path), 404, 'Identity document not found.');
        AdministrativeAuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'actor_role' => $request->user()->role->value,
            'target_user_id' => $provider->id,
            'target_role' => $provider->role->value,
            'action' => 'provider.identity_document_viewed',
            'context' => ['document_type' => $provider->providerProfile?->identity_document_type],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return Storage::disk($disk)->download($path);
    }

    public function submitForApproval(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->providerProfile;
        $ready = $user->hasVerifiedEmail()
            && filled($user->phone)
            && filled($profile?->business_name)
            && filled($profile?->bio)
            && $profile?->identity_document_path
            && $profile?->latitude !== null
            && $profile?->longitude !== null
            && $user->services()->where('is_active', true)->exists();
        if (! $ready) {
            return response()->json([
                'message' => 'Complete contact verification, professional details, service location, identity document, and at least one active service before submitting.',
                'code' => 'provider_profile_incomplete',
            ], 422);
        }

        if ($user->provider_verification_status === ProviderVerificationStatus::Rejected) {
            $user->forceFill(['provider_verification_status' => ProviderVerificationStatus::Pending])->save();
        }

        return response()->json([
            'message' => 'Your profile is submitted for administrator approval.',
            'data' => ['verification_status' => $user->refresh()->provider_verification_status->value],
        ]);
    }

    public function services(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->services()->with('category')->latest()->get()]);
    }

    public function createService(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('service_categories', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
            'base_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'price_unit' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $service = $request->user()->services()->create($validated);

        return response()->json(['data' => $service->load('category')], 201);
    }

    public function updateService(Request $request, int $service): JsonResponse
    {
        $service = $request->user()->services()->findOrFail($service);
        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'integer', Rule::exists('service_categories', 'id')->where('is_active', true)],
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:160'],
            'description' => ['sometimes', 'required', 'string', 'min:10', 'max:3000'],
            'base_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'price_unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $service->update($validated);

        return response()->json(['data' => $service->fresh()->load('category')]);
    }

    public function deleteService(Request $request, int $service): JsonResponse
    {
        $service = $request->user()->services()->findOrFail($service);
        if ($service->bookings()->whereIn('status', [
            BookingStatus::Matched,
            BookingStatus::Accepted,
            BookingStatus::EnRoute,
            BookingStatus::Arrived,
            BookingStatus::InProgress,
        ])->exists()) {
            return response()->json([
                'message' => 'A service with active bookings cannot be deleted. Deactivate it instead.',
                'code' => 'service_has_active_bookings',
            ], 409);
        }
        $service->update(['is_active' => false]);

        return response()->json(['message' => 'Service deactivated.']);
    }

    public function portfolio(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->providerPortfolioItems()
            ->orderBy('sort_order')->get()->map(fn (ProviderPortfolioItem $item) => $this->portfolioData($item))]);
    }

    public function addPortfolioItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:160'],
        ]);
        $path = $request->file('image')->storePublicly('providers/portfolio', 'public');
        if (! $path) {
            return response()->json([
                'message' => 'The portfolio image could not be saved.',
                'code' => 'file_storage_failed',
            ], 500);
        }
        $item = $request->user()->providerPortfolioItems()->create([
            'image_path' => Storage::disk('public')->url($path),
            'caption' => $validated['caption'] ?? null,
            'sort_order' => $request->user()->providerPortfolioItems()->count(),
        ]);

        return response()->json(['data' => $this->portfolioData($item)], 201);
    }

    public function deletePortfolioItem(Request $request, int $item): JsonResponse
    {
        $item = $request->user()->providerPortfolioItems()->findOrFail($item);
        if (str_contains($item->image_path, '/storage/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', (string) parse_url($item->image_path, PHP_URL_PATH)));
        }
        $item->delete();

        return response()->json(['message' => 'Portfolio image removed.']);
    }

    public function bookings(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $query = $request->user()->providerBookings()
            ->with(['customer:id,name,phone', 'service.category', 'statusEvents'])->latest();
        if ($status === 'completed') {
            $query->where('status', BookingStatus::Completed);
        } elseif ($status === 'active') {
            $query->whereIn('status', [
                BookingStatus::Matched,
                BookingStatus::Accepted,
                BookingStatus::EnRoute,
                BookingStatus::Arrived,
                BookingStatus::InProgress,
            ]);
        }

        return response()->json($query->paginate(20));
    }

    public function updateBooking(UpdateBookingStatusRequest $request, int $booking): JsonResponse
    {
        $validated = $request->validated();
        $result = DB::transaction(function () use ($request, $booking, $validated): array {
            $booking = $request->user()->providerBookings()->lockForUpdate()->findOrFail($booking);
            $previous = $booking->status;
            $next = BookingStatus::from($validated['status']);
            $allowed = match ($booking->status) {
                BookingStatus::Matched => [BookingStatus::Accepted],
                BookingStatus::Accepted => [BookingStatus::EnRoute],
                BookingStatus::EnRoute => [BookingStatus::Arrived],
                BookingStatus::Arrived => [BookingStatus::InProgress],
                BookingStatus::InProgress => [BookingStatus::Completed],
                default => [],
            };
            if (! in_array($next, $allowed, true)) {
                return ['conflict' => true];
            }
            $booking->update([
                'status' => $next,
                'completed_at' => $next === BookingStatus::Completed ? now() : null,
                ...array_intersect_key($validated, array_flip(['quote_amount'])),
            ]);
            BookingStatusEvent::query()->create([
                'booking_id' => $booking->id,
                'actor_user_id' => $request->user()->id,
                'from_status' => $previous->value,
                'to_status' => $next->value,
                'created_at' => now(),
            ]);
            if ($next === BookingStatus::Completed && $booking->provider_was_available) {
                $profile = $request->user()->providerProfile;
                if ($this->canReturnOnline($request->user(), $profile)) {
                    $profile->update(['is_available' => true, 'available_since' => now()]);
                }
            }

            return ['booking' => $booking->fresh()->load(['customer:id,name,phone', 'service.category', 'statusEvents'])];
        });
        if (isset($result['conflict'])) {
            return response()->json([
                'message' => 'That booking status transition is not allowed.',
                'code' => 'booking_transition_not_allowed',
            ], 409);
        }

        return (new BookingResource($result['booking']))->response();
    }

    public function cancelBooking(Request $request, int $booking): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $result = DB::transaction(function () use ($request, $booking, $validated): array {
            $provider = $request->user();
            $booking = $provider->providerBookings()->lockForUpdate()->findOrFail($booking);
            if (! in_array($booking->status, [
                BookingStatus::Matched,
                BookingStatus::Accepted,
                BookingStatus::EnRoute,
            ], true)) {
                return ['conflict' => true];
            }
            $previous = $booking->status;
            $attempt = $booking->matching_attempt + 1;
            $booking->matchingAttempts()
                ->where('attempt', $booking->matching_attempt)
                ->whereIn('status', ['searching', 'offered', 'accepted'])
                ->update([
                    'status' => 'provider_cancelled',
                    'failure_reason' => $validated['reason'],
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            $booking->providerExclusions()->firstOrCreate(
                ['provider_id' => $provider->id],
                ['reason' => $validated['reason'], 'created_at' => now()],
            );
            $booking->providerOffers()
                ->where('provider_id', $provider->id)
                ->where('status', ProviderOfferStatus::Accepted)
                ->update([
                    'status' => ProviderOfferStatus::Cancelled->value,
                    'responded_at' => now(),
                    'updated_at' => now(),
                ]);
            $booking->update([
                'provider_id' => null,
                'status' => BookingStatus::PendingMatching,
                'matching_attempt' => $attempt,
                'matching_started_at' => now(),
                'matched_at' => null,
                'provider_was_available' => false,
                'quote_amount' => null,
            ]);
            BookingStatusEvent::query()->create([
                'booking_id' => $booking->id,
                'actor_user_id' => $provider->id,
                'from_status' => $previous->value,
                'to_status' => BookingStatus::PendingMatching->value,
                'reason' => $validated['reason'],
                'created_at' => now(),
            ]);
            $booking->matchingAttempts()->create([
                'attempt' => $attempt,
                'status' => 'searching',
                'started_at' => now(),
            ]);
            $profile = $provider->providerProfile;
            if ($this->canReturnOnline($provider, $profile)) {
                $profile->update(['is_available' => true, 'available_since' => now()]);
            }
            DB::afterCommit(function () use ($booking): void {
                MatchBookingJob::dispatch($booking->id);
            });

            return ['booking' => $booking->fresh()->load(['customer:id,name,phone', 'service.category', 'statusEvents'])];
        });
        if (isset($result['conflict'])) {
            return response()->json([
                'message' => 'A provider can cancel only before arrival. Contact Neybor support if the service has started.',
                'code' => 'provider_cancellation_not_allowed',
            ], 409);
        }

        return (new BookingResource($result['booking']))->response();
    }

    public function notifications(Request $request): JsonResponse
    {
        return response()->json($request->user()->notifications()->latest()->paginate(20));
    }

    public function readNotification(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return response()->json(['message' => 'Notification marked as read.']);
    }

    private function profileData(User $user): array
    {
        $profile = $user->providerProfile()->with('portfolioItems')->firstOrCreate([]);
        $profile->loadMissing('portfolioItems');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'email_verified' => $user->hasVerifiedEmail(),
            'verification_status' => $user->provider_verification_status->value,
            'business_name' => $profile->business_name,
            'bio' => $profile->bio,
            'skills' => $profile->skills ?? [],
            'avatar_url' => $profile->avatar_url,
            'latitude' => $profile->latitude,
            'longitude' => $profile->longitude,
            'coverage_radius_km' => (float) $profile->coverage_radius_km,
            'is_available' => $profile->is_available,
            'identity_document_type' => $profile->identity_document_type,
            'identity_submitted_at' => $profile->identity_submitted_at,
            'has_identity_document' => (bool) $profile->identity_document_path,
            'portfolio' => $profile->portfolioItems->map(fn (ProviderPortfolioItem $item) => $this->portfolioData($item)),
            'services' => $user->services()->with('category')->latest()->get(),
        ];
    }

    private function canReturnOnline(User $provider, $profile): bool
    {
        return $profile
            && $provider->provider_verification_status === ProviderVerificationStatus::Verified
            && $provider->hasVerifiedEmail()
            && ! $provider->isSuspended()
            && filled($profile->identity_document_path)
            && $provider->services()->where('is_active', true)->exists()
            && ! $provider->providerBookings()->whereIn('status', [
                BookingStatus::Matched,
                BookingStatus::Accepted,
                BookingStatus::EnRoute,
                BookingStatus::Arrived,
                BookingStatus::InProgress,
            ])->exists();
    }

    private function portfolioData(ProviderPortfolioItem $item): array
    {
        return [
            'id' => $item->id,
            'image_url' => $item->image_path,
            'caption' => $item->caption,
        ];
    }

    private function incomingOffers($offers): array
    {
        return $offers->map(fn (ProviderOffer $offer): array => [
            'id' => $offer->id,
            'expires_at' => $offer->expires_at,
            'distance_km' => (float) $offer->distance_km,
            'booking' => [
                'id' => $offer->booking->id,
                'scheduled_at' => $offer->booking->scheduled_at,
                'notes' => $offer->booking->notes,
                'location_preview' => 'Within '.number_format((float) $offer->distance_km, 1).' km of your service location',
                'customer_label' => 'Neybor customer',
            ],
            'service' => $offer->service,
        ])->all();
    }
}
