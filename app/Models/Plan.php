<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'creator_id',
        'title',
        'description',
        'plan_date',
        'plan_time',
        'location',
        'latitude',
        'longitude',
        'invite_code',
        'status',
        'banner_color',
        'is_archived',
        'is_deleted',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'plan_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function posts()
    {
        return $this->hasMany(PlanPost::class);
    }
}