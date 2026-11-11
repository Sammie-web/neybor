<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BookingStatus;
use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AdministrativeAuditLog;
use App\Models\Booking;
use App\Models\PlatformSetting;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\WaitlistRegistration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MarketplaceAdminController extends Controller
{
    private const ACTIVE_BOOKING_STATUSES = [
        BookingStatus::Matched,
        BookingStatus::Accepted,
        BookingStatus::EnRoute,
        BookingStatus::Arrived,
        BookingStatus::InProgress,
    ];

    public function overview(): JsonResponse
    {
        $bookingStates = Booking::query()->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status');
        $activity = DB::table('bookings')
            ->selectRaw('DATE(created_at) as date, COUNT(*) as bookings')
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        return response()->json(['data' => [
            'customers' => User::query()->where('role', UserRole::Customer)->count(),
            'providers' => User::query()->where('role', UserRole::ServiceProvider)->count(),
            'pending_verifications' => User::query()
                ->where('role', UserRole::ServiceProvider)
                ->where('provider_verification_status', ProviderVerificationStatus::Pending)->count(),
            'active_bookings' => Booking::query()->whereIn('status', self::ACTIVE_BOOKING_STATUSES)->count(),
            'bookings_total' => Booking::query()->count(),
            'completed_bookings' => Booking::query()->where('status', BookingStatus::Completed)->count(),
            'waitlist_total' => WaitlistRegistration::query()->count(),
            'booking_states' => $bookingStates,
            'booking_activity' => $activity,
            'recent_audit_logs' => AdministrativeAuditLog::query()
                ->latest('created_at')->latest('id')->limit(8)->get(),
        ]]);
    }

    public function services(Request $request): JsonResponse
    {
        if (in_array($request->query('active'), ['true', 'false'], true)) {
            $request->merge(['active' => $request->query('active') === 'true']);
        }

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $services = Service::query()->with(['provider:id,name,email,suspended_at', 'category:id,name,slug'])
            ->when(isset($filters['search']) && $filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['search'];
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->where('name', 'like', "%{$search}%")
                        ->orWhereHas('provider', fn (Builder $provider) => $provider
                            ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(array_key_exists('active', $filters), fn (Builder $query) => $query->where('is_active', $filters['active']))
            ->latest()->paginate($filters['per_page'] ?? 20);

        return response()->json($services);
    }

    public function moderateService(Request $request, Service $service): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $service, $validated): void {
            $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
            $lockedService->update(['is_active' => $validated['is_active']]);
            if (! $validated['is_active']
                && ! $lockedService->provider->services()->where('is_active', true)->exists()) {
                $lockedService->provider->providerProfile()->update([
                    'is_available' => false,
                    'available_since' => null,
                ]);
            }
            $this->audit($request, $lockedService->provider_id, 'service.moderation_changed', [
                'service_id' => $lockedService->id,
                'is_active' => $validated['is_active'],
                'reason' => $validated['reason'],
            ]);
        });

        return response()->json(['data' => $service->fresh()->load(['provider:id,name,email', 'category'])]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::enum(BookingStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $bookings = Booking::query()->with([
            'customer:id,name,email,phone',
            'provider:id,name,email,phone',
            'service:id,name,category_id,base_price,price_unit',
            'service.category:id,name',
        ])
            ->when(isset($filters['status']) && $filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['search']) && $filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['search'];
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->where('requested_address', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $user) => $user
                            ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('provider', fn (Builder $user) => $user
                            ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('service', fn (Builder $service) => $service->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest()->paginate($filters['per_page'] ?? 20);

        return response()->json($bookings);
    }

    public function reviews(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $reviews = Review::query()->with([
            'customer:id,name,email',
            'provider:id,name,email',
            'booking:id,service_id,status',
            'booking.service:id,name',
        ])
            ->when(isset($filters['rating']), fn (Builder $query) => $query->where('rating', $filters['rating']))
            ->when(isset($filters['search']) && $filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['search'];
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->where('comment', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $user) => $user->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('provider', fn (Builder $user) => $user->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest()->paginate($filters['per_page'] ?? 20);

        return response()->json($reviews);
    }

    public function removeReview(Request $request, Review $review): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        DB::transaction(function () use ($request, $review, $validated): void {
            $lockedReview = Review::query()->lockForUpdate()->findOrFail($review->id);
            $target = $lockedReview->provider_id;
            $this->audit($request, $target, 'review.removed', [
                'review_id' => $lockedReview->id,
                'booking_id' => $lockedReview->booking_id,
                'reason' => $validated['reason'],
            ]);
            $lockedReview->delete();
        });

        return response()->json(['message' => 'Review removed.']);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => ServiceCategory::query()->withCount('services')->orderBy('name')->get()]);
    }

    public function createCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:140', 'alpha_dash', 'unique:service_categories,slug'],
            'description' => ['nullable', 'string', 'max:1000'],
            'icon_url' => ['nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $slug = Str::slug($validated['slug'] ?? $validated['name']);
        validator(['slug' => $slug], ['slug' => ['required', 'unique:service_categories,slug']])->validate();
        $category = ServiceCategory::query()->create([
            ...$validated,
            'slug' => $slug,
            'is_active' => $validated['is_active'] ?? true,
        ]);
        $this->audit($request, null, 'service_category.created', ['category_id' => $category->id, 'name' => $category->name]);

        return response()->json(['data' => $category->loadCount('services')], 201);
    }

    public function updateCategory(Request $request, ServiceCategory $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'slug' => ['sometimes', 'required', 'string', 'max:140', 'alpha_dash', Rule::unique('service_categories', 'slug')->ignore($category->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'icon_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $reason = $validated['reason'];
        unset($validated['reason']);
        $category->update($validated);
        $this->audit($request, null, 'service_category.updated', [
            'category_id' => $category->id,
            'changes' => $validated,
            'reason' => $reason,
        ]);

        return response()->json(['data' => $category->fresh()->loadCount('services')]);
    }

    public function deleteCategory(Request $request, ServiceCategory $category): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        if ($category->services()->exists()) {
            return response()->json([
                'message' => 'A category used by services cannot be deleted. Deactivate it instead.',
                'code' => 'category_in_use',
            ], 409);
        }
        $this->audit($request, null, 'service_category.deleted', [
            'category_id' => $category->id,
            'name' => $category->name,
            'reason' => $validated['reason'],
        ]);
        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    public function reports(): JsonResponse
    {
        $monthExpression = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";
        $monthly = Booking::query()
            ->selectRaw($monthExpression.' as month, COUNT(*) as bookings')
            ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->groupBy('month')->orderBy('month')->get();

        return response()->json(['data' => [
            'bookings_by_month' => $monthly,
            'provider_verification' => User::query()
                ->where('role', UserRole::ServiceProvider)
                ->selectRaw('provider_verification_status as status, COUNT(*) as total')
                ->groupBy('provider_verification_status')->get(),
            'waitlist_by_role' => WaitlistRegistration::query()
                ->selectRaw('role, COUNT(*) as total')->groupBy('role')->get(),
            'reviews_by_rating' => Review::query()
                ->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->orderBy('rating')->get(),
            'payments' => [
                'status' => 'not_implemented',
                'revenue' => null,
                'transactions' => null,
            ],
        ]]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->currentSettings()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pricing_model' => ['sometimes', Rule::in(['fixed', 'provider_defined', 'customer_quotation'])],
            'payment_gateways' => ['sometimes', 'array', 'max:2'],
            'payment_gateways.*' => ['string', 'distinct', Rule::in(['paystack', 'flutterwave'])],
            'commission_rate' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'cancellation_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'refund_policy' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ]);
        $current = $this->currentSettings();
        $updated = [...$current, ...$validated, 'payments_enabled' => false];
        PlatformSetting::query()->updateOrCreate(['key' => 'marketplace'], ['value' => $updated]);
        $this->audit($request, null, 'platform.settings_updated', [
            'changed_fields' => array_keys($validated),
        ]);

        return response()->json([
            'data' => $updated,
            'message' => 'Settings saved. Payment collection remains disabled until a payment integration is implemented.',
        ]);
    }

    public function waitlist(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'role' => ['sometimes', Rule::in(['customer', 'provider'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $registrations = WaitlistRegistration::query()
            ->when(isset($filters['role']), fn (Builder $query) => $query->where('role', $filters['role']))
            ->when(isset($filters['search']) && $filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['search'];
                $query->where(fn (Builder $builder) => $builder
                    ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%"));
            })
            ->latest('created_at')->paginate(20);

        return response()->json($registrations);
    }

    private function currentSettings(): array
    {
        $defaults = [
            'pricing_model' => 'provider_defined',
            'payment_gateways' => [],
            'payments_enabled' => false,
            'commission_rate' => null,
            'cancellation_fee' => null,
            'refund_policy' => null,
        ];
        $setting = PlatformSetting::query()->where('key', 'marketplace')->first();

        return [...$defaults, ...($setting?->value ?? [])];
    }

    private function audit(Request $request, ?int $targetUserId, string $action, array $context): void
    {
        $target = $targetUserId ? User::query()->find($targetUserId) : null;
        AdministrativeAuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'actor_role' => $request->user()->role->value,
            'target_user_id' => $target?->id,
            'target_role' => $target?->role->value ?? 'platform',
            'action' => $action,
            'context' => $context,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
