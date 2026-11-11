<?php

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\BookingProgressUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingProgressNotification implements ShouldQueue
{
    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

    public function handle(BookingStatusChanged $event): void
    {
        $booking = Booking::query()->findOrFail($event->bookingId);
        $status = BookingStatus::from($event->toStatus);
        if ($event->actorUserId !== $event->customerId) {
            $customer = User::query()->findOrFail($event->customerId);
            $customer->notify(new BookingProgressUpdated(
                $booking,
                $status,
                $event->reason,
                eventId: $event->eventId,
            ));
        }

        if ($event->actorUserId === $event->customerId && $event->providerId !== null) {
            $provider = User::query()->findOrFail($event->providerId);
            $provider->notify(new BookingProgressUpdated(
                $booking,
                $status,
                $event->reason,
                eventId: $event->eventId,
            ));
        }
    }
}
