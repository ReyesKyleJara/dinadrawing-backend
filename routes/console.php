<?php

use App\Models\Plan;
use App\Models\PlanPost;
use App\Models\PlanBudget;
use App\Models\PlanResponsibilityItem;
use App\Models\User;
use App\Services\ActivityNotifier;
use App\Services\EmailReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Artisan::command('email:test {email}', function (string $email): void {
    Mail::raw(
        'Your DiNaDrawing email configuration is working.',
        function ($message) use ($email): void {
            $message
                ->to($email)
                ->subject('DiNaDrawing email test');
        }
    );

    $this->info('Test email sent to ' . $email);
})->purpose('Send a test email using the current mail configuration');

/*
 * In-app reminders for Activity.
 * Run locally with: php artisan schedule:work
 * On a server, call `php artisan schedule:run` every minute via cron.
 */
Schedule::call(function (): void {
    $now = now();

    PlanPost::query()
        ->with(['plan.members:id', 'votes'])
        ->where('post_type', 'poll')
        ->where('is_voting_closed', false)
        ->whereNull('finalized_at')
        ->whereNotNull('voting_starts_at')
        ->where('voting_starts_at', '<=', $now)
        ->where(function ($query) use ($now): void {
            $query->whereNull('voting_ends_at')
                ->orWhere('voting_ends_at', '>', $now);
        })
        ->get()
        ->each(function (PlanPost $post): void {
            $plan = $post->plan;

            if ($plan === null || $plan->is_deleted || $plan->is_archived) {
                return;
            }

            $votedUserIds = $post->votes
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();

            $recipientIds = collect(
                ActivityNotifier::planRecipientIds(
                    $plan,
                    (int) $post->user_id
                )
            )->reject(
                fn ($id) => in_array((int) $id, $votedUserIds, true)
            );

            ActivityNotifier::notifyUsers(
                recipientUserIds: $recipientIds,
                actorUserId: (int) $post->user_id,
                type: 'poll_vote_required',
                planId: (int) $plan->id,
                planPostId: (int) $post->id,
                data: [
                    'activity_tab' => 'action_required',
                    'requires_action' => true,
                    'action' => 'vote',
                    'poll_question' => (string) $post->poll_question,
                    'voting_ends_at' => optional($post->voting_ends_at)->toISOString(),
                ],
                notificationKey: 'poll:' . $post->id . ':vote',
                replaceExisting: true,
            );
        });

    PlanPost::query()
        ->with(['plan.members:id', 'votes'])
        ->where('post_type', 'poll')
        ->where('is_voting_closed', false)
        ->whereNull('finalized_at')
        ->whereNotNull('voting_ends_at')
        ->whereBetween('voting_ends_at', [
            $now,
            $now->copy()->addHours(2),
        ])
        ->get()
        ->each(function (PlanPost $post) use ($now): void {
            $plan = $post->plan;

            if ($plan === null || $plan->is_deleted || $plan->is_archived) {
                return;
            }

            $votedUserIds = $post->votes
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();

            $recipientIds = collect(
                ActivityNotifier::planRecipientIds(
                    $plan,
                    (int) $post->user_id
                )
            )->reject(
                fn ($id) => in_array((int) $id, $votedUserIds, true)
            );

            $minutesRemaining = max(
                1,
                (int) ceil(
                    $now->diffInMinutes($post->voting_ends_at)
                )
            );

            ActivityNotifier::notifyUsers(
                recipientUserIds: $recipientIds,
                actorUserId: (int) $post->user_id,
                type: 'poll_voting_ending_soon',
                planId: (int) $plan->id,
                planPostId: (int) $post->id,
                data: [
                    'activity_tab' => 'action_required',
                    'requires_action' => true,
                    'action' => 'vote',
                    'poll_question' => (string) $post->poll_question,
                    'minutes_remaining' => $minutesRemaining,
                    'voting_ends_at' => optional($post->voting_ends_at)->toISOString(),
                ],
                notificationKey: 'poll:' . $post->id . ':vote',
                replaceExisting: true,
            );
        });

    PlanPost::query()
        ->with(['plan.members:id'])
        ->where('post_type', 'poll')
        ->where('is_voting_closed', false)
        ->whereNull('finalized_at')
        ->whereNotNull('voting_ends_at')
        ->where('voting_ends_at', '<=', $now)
        ->get()
        ->each(function (PlanPost $post): void {
            $plan = $post->plan;

            if ($plan === null || $plan->is_deleted) {
                return;
            }

            $post->update([
                'is_voting_closed' => true,
            ]);

            ActivityNotifier::deleteByKey(
                'poll:' . $post->id . ':vote'
            );

            ActivityNotifier::notifyPlan(
                plan: $plan,
                actorUserId: null,
                type: 'poll_voting_closed',
                data: [
                    'activity_tab' => 'notifications',
                    'requires_action' => false,
                    'poll_question' => (string) $post->poll_question,
                ],
                planPostId: (int) $post->id,
                notificationKey: 'poll:' . $post->id . ':closed',
                replaceExisting: true,
            );
        });

    Plan::query()
        ->with('members:id')
        ->where('is_deleted', false)
        ->where('is_archived', false)
        ->where('status', 'Plan Ongoing')
        ->whereDate('plan_date', $now->copy()->addDay()->toDateString())
        ->get()
        ->each(function (Plan $plan): void {
            ActivityNotifier::notifyPlan(
                plan: $plan,
                actorUserId: null,
                type: 'plan_happening_tomorrow',
                data: [
                    'activity_tab' => 'notifications',
                    'requires_action' => false,
                    'plan_date' => $plan->plan_date,
                    'plan_time' => $plan->plan_time,
                ],
                notificationKey:
                    'plan:' . $plan->id . ':tomorrow:' . $plan->plan_date,
                replaceExisting: true,
            );
        });

    Plan::query()
        ->with('members:id')
        ->where('is_deleted', false)
        ->where('is_archived', false)
        ->where('status', 'Plan Ongoing')
        ->whereDate('plan_date', $now->toDateString())
        ->whereNotNull('plan_time')
        ->get()
        ->each(function (Plan $plan) use ($now): void {
            $start = Carbon::parse(
                $plan->plan_date . ' ' . $plan->plan_time
            );

            if ($start->lessThan($now) || $start->greaterThan($now->copy()->addHours(2))) {
                return;
            }

            ActivityNotifier::notifyPlan(
                plan: $plan,
                actorUserId: null,
                type: 'plan_happening_soon',
                data: [
                    'activity_tab' => 'notifications',
                    'requires_action' => false,
                    'plan_date' => $plan->plan_date,
                    'plan_time' => $plan->plan_time,
                    'minutes_remaining' => max(
                        1,
                        (int) ceil($now->diffInMinutes($start))
                    ),
                ],
                notificationKey:
                    'plan:' . $plan->id . ':soon:' . $start->format('YmdHi'),
                replaceExisting: true,
            );
        });

    Plan::query()
        ->where('is_deleted', false)
        ->where('is_archived', false)
        ->where('status', 'Plan Ongoing')
        ->whereNull('post_event_checked_at')
        ->whereNotNull('plan_date')
        ->get()
        ->each(function (Plan $plan) use ($now): void {
            $date = Carbon::parse($plan->plan_date);
            $promptAfter = $plan->plan_time
                ? Carbon::parse(
                    $date->toDateString() . ' ' . $plan->plan_time
                )->addHours(12)
                : $date->copy()->addDay()->setTime(9, 0);

            if ($now->lessThan($promptAfter)) {
                return;
            }

            if (
                $plan->post_event_prompt_snoozed_until !== null &&
                $now->lessThan($plan->post_event_prompt_snoozed_until)
            ) {
                return;
            }

            ActivityNotifier::notifyUser(
                recipientUserId: (int) $plan->admin_id,
                actorUserId: null,
                type: 'post_event_check_required',
                planId: (int) $plan->id,
                data: [
                    'activity_tab' => 'action_required',
                    'requires_action' => true,
                    'action' => 'post_event_check',
                ],
                notificationKey:
                    'plan:' . $plan->id . ':post-event-check',
                replaceExisting: true,
            );
        });
})->name('dinadrawing-notification-reminders')
    ->everyMinute()
    ->withoutOverlapping();


/*
 * Critical email reminders. EmailReminderLog prevents duplicate sends even
 * though this scheduler checks every minute.
 */
Schedule::call(function (): void {
    $now = now();
    $today = $now->toDateString();
    $tomorrow = $now->copy()->addDay()->toDateString();

    PlanPost::query()
        ->with(['plan.members:id', 'votes'])
        ->where('post_type', 'poll')
        ->where('is_voting_closed', false)
        ->whereNull('finalized_at')
        ->whereNotNull('voting_ends_at')
        ->whereBetween('voting_ends_at', [
            $now,
            $now->copy()->addHours(2),
        ])
        ->get()
        ->each(function (PlanPost $post) use ($now): void {
            $plan = $post->plan;

            if ($plan === null || $plan->is_deleted || $plan->is_archived) {
                return;
            }

            $votedUserIds = $post->votes
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->unique();

            $recipientIds = collect([(int) $plan->admin_id])
                ->merge($plan->members->pluck('id')->map(fn ($id) => (int) $id))
                ->reject(fn ($id) =>
                    (int) $id === (int) $post->user_id ||
                    $votedUserIds->contains((int) $id)
                )
                ->filter(fn ($id) => (int) $id > 0)
                ->unique()
                ->values();

            $minutesRemaining = max(
                1,
                (int) ceil($now->diffInMinutes($post->voting_ends_at))
            );

            $timeRemaining = $minutesRemaining >= 60
                ? (string) ceil($minutesRemaining / 60) . ' hour(s)'
                : $minutesRemaining . ' minute(s)';

            User::query()
                ->whereIn('id', $recipientIds)
                ->get()
                ->each(function (User $user) use (
                    $post,
                    $plan,
                    $timeRemaining
                ): void {
                    EmailReminderService::sendPollClosingReminder(
                        user: $user,
                        planName: (string) $plan->title,
                        pollQuestion: (string) (
                            $post->poll_question ?? 'A plan poll'
                        ),
                        timeRemaining: $timeRemaining,
                        actionUrl: EmailReminderService::planActionUrl(
                            (int) $plan->id
                        ),
                        reminderKey:
                            'poll:' . $post->id .
                            ':closing:user:' . $user->id
                    );
                });
        });

    Plan::query()
        ->with('members:id')
        ->where('is_deleted', false)
        ->where('is_archived', false)
        ->where('status', 'Plan Ongoing')
        ->whereDate('plan_date', $tomorrow)
        ->get()
        ->each(function (Plan $plan): void {
            $recipientIds = collect([(int) $plan->admin_id])
                ->merge($plan->members->pluck('id')->map(fn ($id) => (int) $id))
                ->filter(fn ($id) => (int) $id > 0)
                ->unique()
                ->values();

            $dateTime = Carbon::parse($plan->plan_date)
                ->format('F j, Y');

            if ($plan->plan_time) {
                $dateTime .= ' at ' . Carbon::parse($plan->plan_time)
                    ->format('g:i A');
            }

            User::query()
                ->whereIn('id', $recipientIds)
                ->get()
                ->each(function (User $user) use ($plan, $dateTime): void {
                    EmailReminderService::sendUpcomingPlanReminder(
                        user: $user,
                        planName: (string) $plan->title,
                        dateTime: $dateTime,
                        actionUrl: EmailReminderService::planActionUrl(
                            (int) $plan->id
                        ),
                        reminderKey:
                            'plan:' . $plan->id .
                            ':upcoming:' . $plan->plan_date .
                            ':user:' . $user->id
                    );
                });
        });

    PlanBudget::query()
        ->with(['plan', 'allocations.user'])
        ->where('contribution_tracking_enabled', true)
        ->whereHas('plan', function ($query) use ($today, $tomorrow): void {
            $query->where('is_deleted', false)
                ->where('is_archived', false)
                ->whereIn('plan_date', [$today, $tomorrow]);
        })
        ->get()
        ->each(function (PlanBudget $budget): void {
            $plan = $budget->plan;

            if ($plan === null) {
                return;
            }

            $dueDate = $plan->plan_date
                ? Carbon::parse($plan->plan_date)->format('F j, Y')
                : 'before the plan';

            foreach ($budget->allocations as $allocation) {
                if (
                    !$allocation->is_included ||
                    $allocation->is_paid ||
                    $allocation->user === null ||
                    (float) $allocation->planned_share <= 0
                ) {
                    continue;
                }

                EmailReminderService::sendPendingPaymentReminder(
                    user: $allocation->user,
                    planName: (string) $plan->title,
                    amount: '₱' . number_format(
                        (float) $allocation->planned_share,
                        2
                    ),
                    dueDate: $dueDate,
                    actionUrl: EmailReminderService::planActionUrl(
                        (int) $plan->id
                    ),
                    reminderKey:
                        'budget-allocation:' . $allocation->id .
                        ':pending:' . $plan->plan_date
                );
            }
        });

    PlanResponsibilityItem::query()
        ->with(['member', 'post.plan'])
        ->whereNotNull('member_user_id')
        ->where(function ($query): void {
            $query->whereNull('contribution')
                ->orWhere('contribution', '');
        })
        ->whereHas('post', function ($query): void {
            $query->where('post_type', 'responsibility')
                ->where('responsibility_mode', 'person_based')
                ->where('responsibility_is_finalized', false);
        })
        ->whereHas('post.plan', function ($query) use ($today, $tomorrow): void {
            $query->where('is_deleted', false)
                ->where('is_archived', false)
                ->whereIn('plan_date', [$today, $tomorrow]);
        })
        ->get()
        ->each(function (PlanResponsibilityItem $item): void {
            $user = $item->member;
            $plan = $item->post?->plan;

            if ($user === null || $plan === null) {
                return;
            }

            EmailReminderService::sendIncompleteContributionReminder(
                user: $user,
                planName: (string) $plan->title,
                contributionType: (string) $item->title,
                actionUrl: EmailReminderService::planActionUrl(
                    (int) $plan->id
                ),
                reminderKey:
                    'responsibility-item:' . $item->id .
                    ':incomplete:' . $plan->plan_date
            );
        });
})->name('dinadrawing-email-reminders')
    ->everyMinute()
    ->withoutOverlapping();
