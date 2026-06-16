<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
        'banner_image_path',
        'theme_color',
        'is_archived',
        'is_deleted',
        'post_event_checked_at',
        'completed_at',
        'post_event_prompt_snoozed_until',
    ];

    protected $casts = [
        'admin_id' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'is_archived' => 'boolean',
        'is_deleted' => 'boolean',
        'post_event_checked_at' => 'datetime',
        'completed_at' => 'datetime',
        'post_event_prompt_snoozed_until' => 'datetime',
    ];

    protected $appends = [
        'banner_image_url',
    ];

    public function getBannerImageUrlAttribute(): ?string
    {
        if (!$this->banner_image_path) {
            return null;
        }

        $path = Storage::url($this->banner_image_path);
        $request = request();

        if ($request !== null) {
            return $request->getSchemeAndHttpHost() . $path;
        }

        return url($path);
    }

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

    public function invitations()
    {
        return $this->hasMany(
            PlanInvitation::class,
            'plan_id'
        );
    }

    public function activityNotifications()
    {
        return $this->hasMany(
            ActivityNotification::class,
            'plan_id'
        );
    }
}
