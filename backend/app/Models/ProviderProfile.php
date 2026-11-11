<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bio',
        'avatar_url',
        'latitude',
        'longitude',
        'is_available',
        'available_since',
        'coverage_radius_km',
        'business_name',
        'skills',
        'identity_document_type',
        'identity_document_path',
        'identity_submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'available_since' => 'immutable_datetime',
            'coverage_radius_km' => 'decimal:2',
            'skills' => 'array',
            'identity_submitted_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function portfolioItems(): HasMany
    {
        return $this->hasMany(ProviderPortfolioItem::class, 'provider_id', 'user_id')
            ->orderBy('sort_order');
    }
}
