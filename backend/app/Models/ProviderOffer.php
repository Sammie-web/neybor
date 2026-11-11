<?php

namespace App\Models;

use App\Enums\ProviderOfferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderOffer extends Model
{
    protected $fillable = [
        'booking_id',
        'provider_id',
        'service_id',
        'matching_attempt',
        'status',
        'score',
        'distance_km',
        'offered_at',
        'expires_at',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProviderOfferStatus::class,
            'score' => 'decimal:6',
            'distance_km' => 'decimal:2',
            'offered_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
