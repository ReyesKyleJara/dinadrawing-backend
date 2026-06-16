<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanPostComment extends Model
{
    protected $fillable = [
        'plan_post_id',
        'user_id',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'plan_post_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(
            PlanPost::class,
            'plan_post_id'
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id'
        );
    }

    public function activityNotifications(): HasMany
    {
        return $this->hasMany(
            ActivityNotification::class,
            'plan_post_comment_id'
        );
    }
}