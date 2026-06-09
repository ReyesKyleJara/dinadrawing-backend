<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanMember extends Model
{
    protected $fillable = [
        'plan_id',
        'user_id',
        'role',
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