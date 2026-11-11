<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'actor_user_id',
    'actor_role',
    'target_user_id',
    'target_role',
    'action',
    'context',
    'ip_address',
    'user_agent',
])]
class AdministrativeAuditLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit logs are immutable.'));
    }
}
