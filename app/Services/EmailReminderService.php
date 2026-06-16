<?php

namespace App\Services;

use App\Mail\AssignmentFinalizedMail;
use App\Mail\AssignmentNotificationMail;
use App\Mail\IncompleteContributionMail;
use App\Mail\PendingPaymentReminderMail;
use App\Mail\PollClosingReminderMail;
use App\Mail\UpcomingPlanReminderMail;
use App\Models\EmailReminderLog;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailReminderService
{
    public static function isEmailRemindersEnabled(User $user): bool
    {
        if (!$user->email_reminders || trim((string) $user->email) === '') {
            return false;
        }

        return $user->email_verified_at !== null ||
            trim((string) $user->oauth_provider) !== '';
    }

    public static function enableEmailReminders(User $user): void
    {
        $user->forceFill([
            'email_reminders' => true,
            'email_reminders_enabled_at' => now(),
        ])->save();
    }

    public static function disableEmailReminders(User $user): void
    {
        $user->forceFill([
            'email_reminders' => false,
            'email_reminders_enabled_at' => null,
        ])->save();
    }

    public static function planActionUrl(int $planId): string
    {
        $base = trim((string) config('app.frontend_url'));

        if ($base === '') {
            return '';
        }

        return rtrim($base, '/') . '/plans/' . $planId;
    }

    public static function sendAssignmentNotification(
        User $user,
        string $planName,
        string $taskDescription,
        string $actionUrl,
        ?string $reminderKey = null
    ): bool {
        return self::sendOnce(
            user: $user,
            type: 'assignment_notification',
            reminderKey: $reminderKey,
            mailable: new AssignmentNotificationMail(
                $user->name,
                $planName,
                $taskDescription,
                $actionUrl
            )
        );
    }

    public static function sendPollClosingReminder(
        User $user,
        string $planName,
        string $pollQuestion,
        string $timeRemaining,
        string $actionUrl,
        ?string $reminderKey = null
    ): bool {
        return self::sendOnce(
            user: $user,
            type: 'poll_closing_reminder',
            reminderKey: $reminderKey,
            mailable: new PollClosingReminderMail(
                $user->name,
                $planName,
                $pollQuestion,
                $timeRemaining,
                $actionUrl
            )
        );
    }

    public static function sendIncompleteContributionReminder(
        User $user,
        string $planName,
        string $contributionType,
        string $actionUrl,
        ?string $reminderKey = null
    ): bool {
        return self::sendOnce(
            user: $user,
            type: 'incomplete_contribution',
            reminderKey: $reminderKey,
            mailable: new IncompleteContributionMail(
                $user->name,
                $planName,
                $contributionType,
                $actionUrl
            )
        );
    }

    public static function sendAssignmentFinalized(
        User $user,
        string $planName,
        string $details,
        string $actionUrl,
        ?string $reminderKey = null
    ): bool {
        return self::sendOnce(
            user: $user,
            type: 'assignment_finalized',
            reminderKey: $reminderKey,
            mailable: new AssignmentFinalizedMail(
                $user->name,
                $planName,
                $details,
                $actionUrl
            )
        );
    }

    public static function sendUpcomingPlanReminder(
        User $user,
        string $planName,
        string $dateTime,
        string $actionUrl,
        ?string $reminderKey = null
    ): bool {
        return self::sendOnce(
            user: $user,
            type: 'upcoming_plan',
            reminderKey: $reminderKey,
            mailable: new UpcomingPlanReminderMail(
                $user->name,
                $planName,
                $dateTime,
                $actionUrl
            )
        );
    }

    public static function sendPendingPaymentReminder(
        User $user,
        string $planName,
        string $amount,
        string $dueDate,
        string $actionUrl,
        ?string $reminderKey = null
    ): bool {
        return self::sendOnce(
            user: $user,
            type: 'pending_payment',
            reminderKey: $reminderKey,
            mailable: new PendingPaymentReminderMail(
                $user->name,
                $planName,
                $amount,
                $dueDate,
                $actionUrl
            )
        );
    }

    private static function sendOnce(
        User $user,
        string $type,
        ?string $reminderKey,
        Mailable $mailable
    ): bool {
        if (!self::isEmailRemindersEnabled($user)) {
            return false;
        }

        $log = null;

        try {
            if ($reminderKey !== null && trim($reminderKey) !== '') {
                $log = EmailReminderLog::firstOrCreate(
                    ['reminder_key' => $reminderKey],
                    [
                        'user_id' => $user->id,
                        'type' => $type,
                        'sent_at' => null,
                    ]
                );

                if (!$log->wasRecentlyCreated && $log->sent_at !== null) {
                    return false;
                }
            }

            Mail::to($user->email)->send($mailable);

            $log?->update([
                'user_id' => $user->id,
                'type' => $type,
                'sent_at' => now(),
            ]);

            return true;
        } catch (Throwable $error) {
            try {
                $log?->delete();
            } catch (Throwable) {
                // Email failures must never break the main app action.
            }

            Log::error('Unable to send DiNaDrawing email reminder.', [
                'user_id' => $user->id,
                'type' => $type,
                'error' => $error->getMessage(),
            ]);

            return false;
        }
    }
}
