<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanPost extends Model
{
    protected $fillable = [
        'plan_id',
        'user_id',
        'post_type',
        'content',
        'image_path',
        'poll_question',
        'poll_options',
        'allow_multiple',
        'anonymous',
        'allow_members_add_options',
        'ends_on',
    ];

    protected $casts = [
        'plan_id' => 'integer',
        'user_id' => 'integer',
        'poll_options' => 'array',
        'allow_multiple' => 'boolean',
        'anonymous' => 'boolean',
        'allow_members_add_options' => 'boolean',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function votes()
    {
        return $this->hasMany(PlanPostVote::class, 'plan_post_id');
    }
}