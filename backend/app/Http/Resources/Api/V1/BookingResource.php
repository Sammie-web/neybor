<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $booking = $this->resource;
        $service = $booking->service;
        $provider = $booking->provider;
        $profile = $provider?->providerProfile;
        $events = $booking->getAttribute('events');
        if ($events === null && $booking->relationLoaded('statusEvents')) {
            $events = $booking->statusEvents->map(fn ($event): array => [
                'status' => $event->to_status,
                'note' => $event->reason,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values();
        }
        $isAssignedProvider = $request->user()?->role === UserRole::ServiceProvider
            && $booking->provider_id === $request->user()?->id
            && ! in_array($booking->status, [
                BookingStatus::PendingMatching,
                BookingStatus::Offered,
            ], true);

        return [
            'id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'provider_id' => $booking->provider_id,
            'service_id' => $booking->service_id,
            'status' => $booking->status->value,
            'requested_address' => $booking->requested_address,
            'latitude' => $booking->latitude === null ? null : (float) $booking->latitude,
            'longitude' => $booking->longitude === null ? null : (float) $booking->longitude,
            'scheduled_at' => $booking->scheduled_at?->toIso8601String(),
            'notes' => $booking->notes,
            'quote_amount' => $booking->quote_amount,
            'matching_attempt' => $booking->matching_attempt,
            'matching_started_at' => $booking->matching_started_at?->toIso8601String(),
            'matched_at' => $booking->matched_at?->toIso8601String(),
            'completed_at' => $booking->completed_at?->toIso8601String(),
            'created_at' => $booking->created_at?->toIso8601String(),
            'service' => $service ? [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'base_price' => $service->base_price,
                'price_unit' => $service->price_unit,
                'category' => $service->category ? [
                    'id' => $service->category->id,
                    'name' => $service->category->name,
                    'slug' => $service->category->slug,
                ] : null,
            ] : null,
            'provider' => $provider ? [
                'id' => $provider->id,
                'name' => $provider->name,
                'business_name' => $profile?->business_name,
                'avatar_url' => $profile?->avatar_url,
                'latitude' => $profile?->latitude,
                'longitude' => $profile?->longitude,
                'is_available' => $profile?->is_available,
                'is_verified' => $provider->provider_verification_status?->value === 'verified'
                    && ! $provider->isSuspended(),
            ] : null,
            'customer' => $isAssignedProvider && $booking->customer ? [
                'id' => $booking->customer->id,
                'name' => $booking->customer->name,
                'phone' => $booking->customer->phone,
            ] : null,
            'matching' => $booking->getAttribute('matching'),
            'events' => $events ?? [],
            'can_cancel' => $booking->getAttribute('can_cancel') ?? (
                $request->user()?->role === UserRole::Customer
                && in_array($booking->status, [
                    BookingStatus::PendingMatching,
                    BookingStatus::Offered,
                    BookingStatus::Matched,
                    BookingStatus::Accepted,
                    BookingStatus::EnRoute,
                    BookingStatus::NoProviderAvailable,
                ], true)
            ),
            'can_review' => $booking->status === BookingStatus::Completed
                && ! $booking->review?->exists,
        ];
    }
}
