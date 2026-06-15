<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BudgetAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'budget_id',
        'user_id',
        'is_included',
        'planned_share',
        'is_paid',
        'paid_at',
        'marked_paid_by',
    ];

    protected function casts(): array
    {
        return [
            'is_included' => 'boolean',
            'planned_share' => 'decimal:2',
            'is_paid' => 'boolean',
            'paid_at' => 'datetime',
        ];
    }

    public function budget()
    {
        return $this->belongsTo(
            PlanBudget::class,
            'budget_id'
        );
    }

    public function user()
    {
        return $this->belongsTo(
            User::class,
            'user_id'
        );
    }

    public function markedPaidBy()
    {
        return $this->belongsTo(
            User::class,
            'marked_paid_by'
        );
    }
}