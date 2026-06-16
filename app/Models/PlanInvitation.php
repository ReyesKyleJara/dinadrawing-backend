<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanInvitation extends Model
{
    protected $fillable = [
        'plan_id',
        'invited_user_id',
        'invited_by',
        'status',
        'responded_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'invited_user_id' => 'integer',
            'invited_by' => 'integer',
            'responded_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(
            Plan::class,
            'plan_id'
        );
    }

    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'invited_user_id'
        );
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'invited_by'
        );
    }
}