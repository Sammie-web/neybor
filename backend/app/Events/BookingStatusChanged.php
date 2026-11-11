<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $eventId,
        public readonly int $bookingId,
        public readonly int $customerId,
        public readonly ?int $providerId,
        public readonly ?int $actorUserId,
        public readonly ?string $fromStatus,
        public readonly string $toStatus,
        public readonly string $occurredAt,
        public readonly ?string $reason = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('bookings.'.$this->bookingId)];
    }

    public function broadcastAs(): string
    {
        return 'booking.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'booking_id' => $this->bookingId,
            'from_status' => $this->fromStatus,
            'status' => $this->toStatus,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
