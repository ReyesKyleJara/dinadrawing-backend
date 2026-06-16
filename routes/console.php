<?php

use App\Models\Plan;
use App\Models\PlanPost;
use App\Services\ActivityNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
