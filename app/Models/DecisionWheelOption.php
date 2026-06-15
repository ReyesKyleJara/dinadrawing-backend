<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionWheelOption extends Model
{
    protected $fillable = ['wheel_id', 'option_name', 'color', 'sort_order'];

    public function wheel(): BelongsTo
    {
        return $this->belongsTo(DecisionWheel::class, 'wheel_id');
    }
}
