<?php

namespace App\Http\Controllers;

use App\Models\ActivityNotification;
use App\Models\PlanInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityNotificationController extends Controller
{
    /**
     * Return one unified Activity feed containing:
     * - plan invitations
     * - generic activity notifications such as comments
     */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $notifications = ActivityNotification::query()
            ->with([
                'actor:id,name,username,profile_photo_path',
                'plan:id,title,banner_color,status,plan_date,plan_time,location,is_archived,is_deleted',
                'post:id,plan_id,user_id,post_type,content,poll_question,responsibility_title',
            ])
            ->where('recipient_user_id', $userId)
            ->latest()
            ->limit(100)
            ->get();

        $invitations = PlanInvitation::query()
            ->with([
                'inviter:id,name,username,profile_photo_path',
                'plan:id,title,banner_color,status,plan_date,plan_time,location,is_archived,is_deleted',
            ])
            ->where('invited_user_id', $userId)
            ->latest()
            ->limit(100)
            ->get();

        $activities = $notifications
            ->map(
                fn (ActivityNotification $notification): array =>
                    $this->serializeNotification($notification)
            )
            ->concat(
                $invitations->map(
                    fn (PlanInvitation $invitation): array =>
                        $this->serializeInvitation($invitation)
                )
            )
            ->sortByDesc('sort_timestamp')
            ->values()
            ->map(function (array $activity): array {
                unset($activity['sort_timestamp']);

                return $activity;
            })
            ->values();

        $unreadCount = $notifications
            ->whereNull('read_at')
            ->count()
            + $invitations
                ->whereNull('read_at')
                ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
            'activities' => $activities,
        ]);
    }

    /**
     * Mark one generic activity notification as read.
     * Plan invitations keep using their existing invitation read endpoint.
     */
    public function markRead(
        Request $request,
        ActivityNotification $notification
    ): JsonResponse {
        if (
            (int) $notification->recipient_user_id !==
            (int) $request->user()->id
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'You are not allowed to access this notification.',
            ], 403);
        }

        if ($notification->read_at === null) {
            $notification->update([
                'read_at' => now(),
            ]);
        }

        $notification->load([
            'actor:id,name,username,profile_photo_path',
            'plan:id,title,banner_color,status,plan_date,plan_time,location,is_archived,is_deleted',
            'post:id,plan_id,user_id,post_type,content,poll_question,responsibility_title',
        ]);

        return response()->json([
            'success' => true,
            'message' =>
                'Notification marked as read.',
            'activity' =>
                $this->serializeNotification(
                    $notification
                ),
        ]);
    }

    /**
     * Mark every invitation and generic notification as read.
     */
    public function markAllRead(
        Request $request
    ): JsonResponse {
        $userId = (int) $request->user()->id;
        $readAt = now();

        ActivityNotification::query()
            ->where('recipient_user_id', $userId)
            ->whereNull('read_at')
            ->update([
                'read_at' => $readAt,
            ]);

        PlanInvitation::query()
            ->where('invited_user_id', $userId)
            ->whereNull('read_at')
            ->update([
                'read_at' => $readAt,
            ]);

        return response()->json([
            'success' => true,
            'message' =>
                'All activity notifications were marked as read.',
            'unread_count' => 0,
        ]);
    }

    private function serializeNotification(
        ActivityNotification $notification
    ): array {
        $actor = $notification->actor;
        $plan = $notification->plan;
        $post = $notification->post;

        return [
            'activity_key' =>
                'notification:' . $notification->id,
            'source' => 'activity_notification',
            'source_id' =>
                (int) $notification->id,
            'type' =>
                (string) $notification->type,
            'status' => null,
            'is_read' =>
                $notification->read_at !== null,
            'read_at' =>
                optional(
                    $notification->read_at
                )->toISOString(),
            'created_at' =>
                optional(
                    $notification->created_at
                )->toISOString(),
            'sort_timestamp' =>
                optional(
                    $notification->created_at
                )->getTimestamp() ?? 0,
            'data' =>
                $notification->data ?? [],

            'plan' => $plan === null
                ? null
                : [
                    'id' => (int) $plan->id,
                    'title' => (string) $plan->title,
                    'banner_color' => $plan->banner_color,
                    'status' => $plan->status,
                    'plan_date' => $plan->plan_date,
                    'plan_time' => $plan->plan_time,
                    'location' => $plan->location,
                    'is_archived' =>
                        (bool) $plan->is_archived,
                    'is_deleted' =>
                        (bool) $plan->is_deleted,
                ],

            'actor' => $actor === null
                ? null
                : [
                    'id' => (int) $actor->id,
                    'name' => (string) $actor->name,
                    'username' => $actor->username,
                    'profile_photo_path' =>
                        $actor->profile_photo_path,
                ],

            'post' => $post === null
                ? null
                : [
                    'id' => (int) $post->id,
                    'post_type' =>
                        (string) $post->post_type,
                    'content' => $post->content,
                    'poll_question' =>
                        $post->poll_question,
                    'responsibility_title' =>
                        $post->responsibility_title,
                ],
        ];
    }

    private function serializeInvitation(
        PlanInvitation $invitation
    ): array {
        $inviter = $invitation->inviter;
        $plan = $invitation->plan;

        return [
            'activity_key' =>
                'invitation:' . $invitation->id,
            'source' => 'plan_invitation',
            'source_id' =>
                (int) $invitation->id,
            'type' => 'plan_invitation',
            'status' =>
                (string) $invitation->status,
            'is_read' =>
                $invitation->read_at !== null,
            'read_at' =>
                optional(
                    $invitation->read_at
                )->toISOString(),
            'created_at' =>
                optional(
                    $invitation->created_at
                )->toISOString(),
            'sort_timestamp' =>
                optional(
                    $invitation->created_at
                )->getTimestamp() ?? 0,
            'data' => [],

            'plan' => $plan === null
                ? null
                : [
                    'id' => (int) $plan->id,
                    'title' => (string) $plan->title,
                    'banner_color' => $plan->banner_color,
                    'status' => $plan->status,
                    'plan_date' => $plan->plan_date,
                    'plan_time' => $plan->plan_time,
                    'location' => $plan->location,
                    'is_archived' =>
                        (bool) $plan->is_archived,
                    'is_deleted' =>
                        (bool) $plan->is_deleted,
                ],

            'actor' => $inviter === null
                ? null
                : [
                    'id' => (int) $inviter->id,
                    'name' => (string) $inviter->name,
                    'username' => $inviter->username,
                    'profile_photo_path' =>
                        $inviter->profile_photo_path,
                ],

            'post' => null,
        ];
    }
}
