<?php

namespace App\Notifications;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Notifications\Channels\ExpoPushChannel;
use App\Notifications\Channels\IdempotentDatabaseChannel;
use App\Notifications\Concerns\HasStableNotificationId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class BookingProgressUpdated extends Notification implements ShouldQueue
{
    use HasStableNotificationId, Queueable;

    public function __construct(
        private readonly Booking $booking,
        private readonly BookingStatus $status,
        private readonly ?string $reason,
        private readonly int $eventId,
    ) {}

    public function via(object $notifiable): array
    {
        $this->useStableNotificationId('booking-status:'.$this->eventId.':user:'.$notifiable->getKey());

        return [IdempotentDatabaseChannel::class, ExpoPushChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => 'Booking update',
            'body' => $this->message(),
            'data' => [
                'booking_id' => $this->booking->id,
                'status' => $this->status->value,
                'type' => 'booking_progress',
                'notification_id' => $this->id,
            ],
            'sound' => 'default',
        ];
    }

    private function payload(): array
    {
        return [
            'title' => 'Booking update',
            'body' => $this->message(),
            'booking_id' => $this->booking->id,
            'status' => $this->status->value,
            'reason' => $this->reason,
        ];
    }

    private function message(): string
    {
        return match ($this->status) {
            BookingStatus::Matched => 'A professional accepted your request.',
            BookingStatus::Accepted => 'Your professional confirmed the booking.',
            BookingStatus::EnRoute => 'Your professional is on the way.',
            BookingStatus::Arrived => 'Your professional has arrived.',
            BookingStatus::InProgress => 'Your service has started.',
            BookingStatus::Completed => 'Your professional marked the service complete.',
            BookingStatus::PendingMatching => 'Your professional could not take the job. We are finding another Neybor.',
            BookingStatus::Cancelled => 'Your booking was cancelled.',
            default => 'Your booking status has changed.',
        };
    }
}
