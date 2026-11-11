<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaitlistRegistration extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'email',
        'role',
        'phone',
        'business_name',
        'service_interest',
        'source',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
