<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanPostVote extends Model
{
    protected $fillable = [
        'plan_post_id',
        'user_id',
        'option_index',
    ];

    protected $casts = [
        'plan_post_id' => 'integer',
        'user_id' => 'integer',
        'option_index' => 'integer',
    ];

    public function post()
    {
        return $this->belongsTo(PlanPost::class, 'plan_post_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}