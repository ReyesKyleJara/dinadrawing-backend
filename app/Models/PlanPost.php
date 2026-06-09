<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanPost extends Model
{
    protected $fillable = [
        'plan_id',
        'user_id',
        'content',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}