<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlitzPoll extends Model
{
    protected $fillable = ['user_id', 'title', 'duration_seconds', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(BlitzPollOption::class, 'poll_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(BlitzPollVote::class, 'poll_id');
    }
}
