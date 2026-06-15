<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlitzPollOption extends Model
{
    protected $fillable = ['poll_id', 'option_name', 'color'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(BlitzPoll::class, 'poll_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(BlitzPollVote::class, 'option_id');
    }
}
