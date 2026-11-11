<?php

namespace App\Models;

use App\Events\BookingStatusChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingStatusEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'actor_user_id',
        'from_status',
        'to_status',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::created(function (BookingStatusEvent $event): void {
            $booking = $event->booking()->firstOrFail(['id', 'customer_id', 'provider_id']);
            event(new BookingStatusChanged(
                eventId: $event->id,
                bookingId: $booking->id,
                customerId: $booking->customer_id,
                providerId: $booking->provider_id,
                actorUserId: $event->actor_user_id,
                fromStatus: $event->from_status,
                toStatus: $event->to_status,
                occurredAt: $event->created_at->toIso8601String(),
                reason: $event->reason,
            ));
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
