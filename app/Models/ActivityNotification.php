<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityNotification extends Model
{
    protected $fillable = [
        'recipient_user_id',
        'actor_user_id',
        'type',
        'plan_id',
        'plan_post_id',
        'plan_post_comment_id',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'recipient_user_id' => 'integer',
            'actor_user_id' => 'integer',
            'plan_id' => 'integer',
            'plan_post_id' => 'integer',
            'plan_post_comment_id' => 'integer',
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'recipient_user_id'
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'actor_user_id'
        );
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(
            Plan::class,
            'plan_id'
        );
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(
            PlanPost::class,
            'plan_post_id'
        );
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(
            PlanPostComment::class,
            'plan_post_comment_id'
        );
    }
}