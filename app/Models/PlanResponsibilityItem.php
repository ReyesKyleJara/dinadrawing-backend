<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanResponsibilityItem extends Model
{
    protected $fillable = [
        'plan_post_id',
        'member_user_id',
        'title',
        'is_manual',
        'contribution',
        'slots',
        'position',
    ];

    protected $casts = [
        'plan_post_id' => 'integer',
        'member_user_id' => 'integer',
        'is_manual' => 'boolean',
        'slots' => 'integer',
        'position' => 'integer',
    ];

    public function post()
    {
        return $this->belongsTo(
            PlanPost::class,
            'plan_post_id'
        );
    }

    public function member()
    {
        return $this->belongsTo(
            User::class,
            'member_user_id'
        );
    }

    public function assignments()
    {
        return $this->hasMany(
            PlanResponsibilityAssignment::class,
            'responsibility_item_id'
        );
    }
}