<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'admin_id',
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

    protected $casts = [
        'admin_id' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'is_archived' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    public function admin()
    {
        return $this->belongsTo(
            User::class,
            'admin_id'
        );
    }

    public function members()
    {
        return $this->belongsToMany(
            User::class,
            'plan_members'
        )
            ->withPivot('role')
            ->withTimestamps();
    }

    public function posts()
    {
        return $this->hasMany(
            PlanPost::class
        );
    }

    public function responsibilityPosts()
    {
        return $this->hasMany(
            PlanPost::class
        )->where(
            'post_type',
            'responsibility'
        );
    }

    public function budget()
    {
        return $this->hasOne(
            PlanBudget::class,
            'plan_id'
        );
    }
}