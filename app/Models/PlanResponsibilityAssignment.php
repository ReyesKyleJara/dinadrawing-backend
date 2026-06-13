<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanResponsibilityAssignment extends Model
{
    protected $fillable = [
        'responsibility_item_id',
        'user_id',
        'manual_name',
        'status',
        'source',
    ];

    protected $casts = [
        'responsibility_item_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function item()
    {
        return $this->belongsTo(
            PlanResponsibilityItem::class,
            'responsibility_item_id'
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}