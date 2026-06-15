<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanBudget extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'created_by',
        'updated_by',
        'split_type',
        'contribution_tracking_enabled',
        'allow_member_mark_paid',
        'show_status_to_members',
        'total_estimated',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'contribution_tracking_enabled' => 'boolean',
            'allow_member_mark_paid' => 'boolean',
            'show_status_to_members' => 'boolean',
            'total_estimated' => 'decimal:2',
            'published_at' => 'datetime',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(
            Plan::class,
            'plan_id'
        );
    }

    public function creator()
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function updater()
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }

    public function expenses()
    {
        return $this->hasMany(
            BudgetExpense::class,
            'budget_id'
        )->orderBy('position');
    }

    public function allocations()
    {
        return $this->hasMany(
            BudgetAllocation::class,
            'budget_id'
        );
    }

    /*
     * Total of all included member shares.
     */
    public function getAllocatedAmountAttribute(): float
    {
        return (float) $this->allocations()
            ->where('is_included', true)
            ->sum('planned_share');
    }

    /*
     * Estimated amount that still has not been assigned.
     */
    public function getUnallocatedAmountAttribute(): float
    {
        $remaining = (float) $this->total_estimated
            - $this->allocated_amount;

        return round($remaining, 2);
    }

    /*
     * Total contributions marked as Paid.
     *
     * This remains stored even when tracking is temporarily disabled.
     */
    public function getCollectedAmountAttribute(): float
    {
        return (float) $this->allocations()
            ->where('is_included', true)
            ->where('is_paid', true)
            ->sum('planned_share');
    }

    public function getNotCollectedAmountAttribute(): float
    {
        $remaining = $this->allocated_amount
            - $this->collected_amount;

        return round(max($remaining, 0), 2);
    }
}