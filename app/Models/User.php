<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',

        // Profile settings
        'profile_photo_path',
        'username_changed_at',

        // Notification preferences
        'email_reminders',
        'push_notifications',
        'in_app_alerts',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'username_changed_at' => 'datetime',

            'email_reminders' => 'boolean',
            'push_notifications' => 'boolean',
            'in_app_alerts' => 'boolean',

            'password' => 'hashed',
        ];
    }

    /**
     * Person-based responsibility entries connected to this user.
     */
    public function responsibilityItems(): HasMany
    {
        return $this->hasMany(
            PlanResponsibilityItem::class,
            'member_user_id'
        );
    }

    /**
     * Role/task assignments claimed or assigned to this user.
     */
    public function responsibilityAssignments(): HasMany
    {
        return $this->hasMany(
            PlanResponsibilityAssignment::class,
            'user_id'
        );
    }

    public function createdPlanBudgets()
{
    return $this->hasMany(
        PlanBudget::class,
        'created_by'
    );
}

public function budgetAllocations()
{
    return $this->hasMany(
        BudgetAllocation::class,
        'user_id'
    );
}

public function markedBudgetPayments()
{
    return $this->hasMany(
        BudgetAllocation::class,
        'marked_paid_by'
    );
}
}