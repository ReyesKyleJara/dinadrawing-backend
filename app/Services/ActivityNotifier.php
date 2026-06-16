<?php

namespace App\Services;

use App\Models\ActivityNotification;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Collection;

class ActivityNotifier
{
    public static function planRecipientIds(
        Plan $plan,
        ?int $excludeUserId = null
    ): array {
        $plan->loadMissing('members:id');

        $ids = collect([(int) $plan->admin_id])
            ->merge($plan->members->pluck('id')->map(fn ($id) => (int) $id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->when(
                $excludeUserId !== null,
                fn (Collection $collection) => $collection->reject(
                    fn ($id) => (int) $id === (int) $excludeUserId
                )
            )
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids->all())
            ->where(function ($query): void {
                $query->where('in_app_alerts', true)
                    ->orWhereNull('in_app_alerts');
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public static function notifyPlan(
        Plan $plan,
        ?int $actorUserId,
        string $type,
        array $data = [],
        ?int $planPostId = null,
        ?int $planPostCommentId = null,
        ?int $excludeUserId = null,
        ?string $notificationKey = null,
        bool $replaceExisting = false
    ): void {
        foreach (self::planRecipientIds($plan, $excludeUserId) as $recipientId) {
            self::notifyUser(
                recipientUserId: $recipientId,
                actorUserId: $actorUserId,
                type: $type,
                planId: (int) $plan->id,
                planPostId: $planPostId,
                planPostCommentId: $planPostCommentId,
                data: $data,
                notificationKey: $notificationKey,
                replaceExisting: $replaceExisting,
            );
        }
    }

    public static function notifyUsers(
        iterable $recipientUserIds,
        ?int $actorUserId,
        string $type,
        ?int $planId = null,
        ?int $planPostId = null,
        ?int $planPostCommentId = null,
        array $data = [],
        ?string $notificationKey = null,
        bool $replaceExisting = false
    ): void {
        foreach (collect($recipientUserIds)->map(fn ($id) => (int) $id)->unique() as $recipientId) {
            self::notifyUser(
                recipientUserId: $recipientId,
                actorUserId: $actorUserId,
                type: $type,
                planId: $planId,
                planPostId: $planPostId,
                planPostCommentId: $planPostCommentId,
                data: $data,
                notificationKey: $notificationKey,
                replaceExisting: $replaceExisting,
            );
        }
    }

    public static function notifyUser(
        int $recipientUserId,
        ?int $actorUserId,
        string $type,
        ?int $planId = null,
        ?int $planPostId = null,
        ?int $planPostCommentId = null,
        array $data = [],
        ?string $notificationKey = null,
        bool $replaceExisting = false
    ): ?ActivityNotification {
        if (
            $recipientUserId <= 0 ||
            ($actorUserId !== null && $recipientUserId === (int) $actorUserId)
        ) {
            return null;
        }

        $recipientAllowsAlerts = User::query()
            ->whereKey($recipientUserId)
            ->where(function ($query): void {
                $query->where('in_app_alerts', true)
                    ->orWhereNull('in_app_alerts');
            })
            ->exists();

        if (!$recipientAllowsAlerts) {
            return null;
        }

        if ($notificationKey !== null && $notificationKey !== '') {
            $data['notification_key'] = $notificationKey;
        }

        $existing = null;

        if ($notificationKey !== null && $notificationKey !== '') {
            $existing = ActivityNotification::query()
                ->where('recipient_user_id', $recipientUserId)
                ->where('data->notification_key', $notificationKey)
                ->latest('id')
                ->first();
        }

        if ($existing !== null && $replaceExisting) {
            $existing->update([
                'actor_user_id' => $actorUserId,
                'type' => $type,
                'plan_id' => $planId,
                'plan_post_id' => $planPostId,
                'plan_post_comment_id' => $planPostCommentId,
                'data' => $data,
                'read_at' => null,
                'updated_at' => now(),
            ]);

            return $existing->fresh();
        }

        if ($existing !== null) {
            return $existing;
        }

        return ActivityNotification::create([
            'recipient_user_id' => $recipientUserId,
            'actor_user_id' => $actorUserId,
            'type' => $type,
            'plan_id' => $planId,
            'plan_post_id' => $planPostId,
            'plan_post_comment_id' => $planPostCommentId,
            'data' => $data,
            'read_at' => null,
        ]);
    }

    public static function deleteByKey(
        string $notificationKey,
        ?int $recipientUserId = null
    ): void {
        $query = ActivityNotification::query()
            ->where('data->notification_key', $notificationKey);

        if ($recipientUserId !== null) {
            $query->where('recipient_user_id', $recipientUserId);
        }

        $query->delete();
    }

    public static function deleteForPostByTypes(
        int $planPostId,
        array $types,
        ?int $recipientUserId = null
    ): void {
        $query = ActivityNotification::query()
            ->where('plan_post_id', $planPostId)
            ->whereIn('type', $types);

        if ($recipientUserId !== null) {
            $query->where('recipient_user_id', $recipientUserId);
        }

        $query->delete();
    }

    public static function resolveByKey(
        string $notificationKey,
        string $type,
        array $data = [],
        ?int $recipientUserId = null,
        bool $markRead = true
    ): void {
        $query = ActivityNotification::query()
            ->where('data->notification_key', $notificationKey);

        if ($recipientUserId !== null) {
            $query->where('recipient_user_id', $recipientUserId);
        }

        foreach ($query->get() as $notification) {
            $existingData = is_array($notification->data)
                ? $notification->data
                : [];

            $notification->update([
                'type' => $type,
                'data' => [
                    ...$existingData,
                    ...$data,
                    'requires_action' => false,
                    'activity_tab' => 'notifications',
                ],
                'read_at' => $markRead
                    ? ($notification->read_at ?? now())
                    : null,
            ]);
        }
    }

    public static function deleteByTypesForPlan(
        int $planId,
        array $types,
        ?int $recipientUserId = null
    ): void {
        $query = ActivityNotification::query()
            ->where('plan_id', $planId)
            ->whereIn('type', $types);

        if ($recipientUserId !== null) {
            $query->where('recipient_user_id', $recipientUserId);
        }

        $query->delete();
    }
}
