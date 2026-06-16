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
        'email_verified_at',
        'profile_photo_path',
        'username_changed_at',
        'email_reminders',
        'email_reminders_enabled_at',
        'push_notifications',
        'in_app_alerts',
        'oauth_provider',
        'oauth_uid',
        'oauth_avatar_url',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'oauth_uid',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'username_changed_at' => 'datetime',
            'email_reminders_enabled_at' => 'datetime',
            'email_reminders' => 'boolean',
            'push_notifications' => 'boolean',
            'in_app_alerts' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function responsibilityItems(): HasMany
    {
        return $this->hasMany(
            PlanResponsibilityItem::class,
            'member_user_id'
        );
    }

    public function responsibilityAssignments(): HasMany
    {
        return $this->hasMany(
            PlanResponsibilityAssignment::class,
            'user_id'
        );
    }

    public function createdPlanBudgets(): HasMany
    {
        return $this->hasMany(PlanBudget::class, 'created_by');
    }

    public function budgetAllocations(): HasMany
    {
        return $this->hasMany(BudgetAllocation::class, 'user_id');
    }

    public function markedBudgetPayments(): HasMany
    {
        return $this->hasMany(BudgetAllocation::class, 'marked_paid_by');
    }

    public function receivedPlanInvitations(): HasMany
    {
        return $this->hasMany(PlanInvitation::class, 'invited_user_id');
    }

    public function sentPlanInvitations(): HasMany
    {
        return $this->hasMany(PlanInvitation::class, 'invited_by');
    }

    public function planPostComments(): HasMany
    {
        return $this->hasMany(PlanPostComment::class, 'user_id');
    }

    public function receivedActivityNotifications(): HasMany
    {
        return $this->hasMany(ActivityNotification::class, 'recipient_user_id');
    }

    public function triggeredActivityNotifications(): HasMany
    {
        return $this->hasMany(ActivityNotification::class, 'actor_user_id');
    }

    public function emailVerificationCodes(): HasMany
    {
        return $this->hasMany(EmailVerificationCode::class);
    }

    public function emailReminderLogs(): HasMany
    {
        return $this->hasMany(EmailReminderLog::class);
    }
}
