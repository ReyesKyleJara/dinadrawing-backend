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

        // Poll
        'poll_question',
        'poll_options',
        'allow_multiple',
        'anonymous',
        'allow_members_add_options',
        'ends_on',
        'is_pinned',
        'voting_starts_at',
        'voting_ends_at',
        'is_voting_closed',

        // Responsibilities
        'responsibility_title',
        'responsibility_mode',
        'responsibility_allow_member_items',
        'responsibility_show_progress',
        'responsibility_is_finalized',
    ];

    protected $casts = [
        'plan_id' => 'integer',
        'user_id' => 'integer',

        // Poll
        'poll_options' => 'array',
        'allow_multiple' => 'boolean',
        'anonymous' => 'boolean',
        'allow_members_add_options' => 'boolean',
        'is_pinned' => 'boolean',
        'voting_starts_at' => 'datetime',
        'voting_ends_at' => 'datetime',
        'is_voting_closed' => 'boolean',

        // Responsibilities
        'responsibility_allow_member_items' => 'boolean',
        'responsibility_show_progress' => 'boolean',
        'responsibility_is_finalized' => 'boolean',
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
        return $this->hasMany(
            PlanPostVote::class,
            'plan_post_id'
        );
    }

    public function responsibilityItems()
    {
        return $this->hasMany(
            PlanResponsibilityItem::class,
            'plan_post_id'
        )->orderBy('position');
    }
}