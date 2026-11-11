<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'service_id', 'provider_id', 'status', 'requested_address',
        'latitude', 'longitude', 'scheduled_at', 'notes', 'completed_at',
        'matching_attempt', 'matching_started_at', 'matched_at',
        'quote_amount',
        'provider_was_available',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'scheduled_at' => 'immutable_datetime',
            'quote_amount' => 'decimal:2',
            'provider_was_available' => 'boolean',
            'completed_at' => 'immutable_datetime',
            'matching_started_at' => 'immutable_datetime',
            'matched_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function providerOffers(): HasMany
    {
        return $this->hasMany(ProviderOffer::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(BookingStatusEvent::class)->orderBy('created_at');
    }

    public function providerExclusions(): HasMany
    {
        return $this->hasMany(BookingProviderExclusion::class);
    }

    public function matchingAttempts(): HasMany
    {
        return $this->hasMany(BookingMatchingAttempt::class)->orderBy('attempt');
    }
}
