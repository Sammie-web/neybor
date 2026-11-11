<?php

use App\Models\Booking;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return ! $user->isSuspended() && (int) $user->id === (int) $id;
});

Broadcast::channel('bookings.{bookingId}', function ($user, int $bookingId): bool {
    if ($user->isSuspended()) {
        return false;
    }

    return Booking::query()
        ->whereKey($bookingId)
        ->where(function ($query) use ($user): void {
            $query->where('customer_id', $user->id)
                ->orWhere('provider_id', $user->id);
        })
        ->exists();
});
