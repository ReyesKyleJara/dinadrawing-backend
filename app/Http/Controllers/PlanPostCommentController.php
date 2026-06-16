<?php

namespace App\Http\Controllers;

use App\Models\ActivityNotification;
use App\Models\PlanPost;
use App\Models\PlanPostComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanPostCommentController extends Controller
{
    /*
     * Return all comments for one post.
     */
    public function index(
        Request $request,
        PlanPost $post
    ): JsonResponse {
        $plan = $post->plan;
        $user = $request->user();

        if (
            $plan === null ||
            $plan->is_deleted
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found.',
            ], 404);
        }

        if (
            !$this->canAccessPlan(
                $plan,
                $user->id
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'You are not allowed to view comments in this plan.',
            ], 403);
        }

        $comments = $post
            ->comments()
            ->with([
                'user:id,name,username,email,profile_photo_path',
            ])
            ->get()
            ->map(
                fn (
                    PlanPostComment $comment
                ) =>
                    $this->serializeComment(
                        $comment,
                        $user->id,
                        $plan->admin_id
                    )
            )
            ->values();

        return response()->json([
            'success' => true,
            'comment_count' =>
                $comments->count(),
            'comments' => $comments,
        ]);
    }

    /*
     * Add a comment to a post.
     */
    public function store(
        Request $request,
        PlanPost $post
    ): JsonResponse {
        $plan = $post->plan;
        $user = $request->user();

        if (
            $plan === null ||
            $plan->is_deleted
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found.',
            ], 404);
        }

        if (
            !$this->canAccessPlan(
                $plan,
                $user->id
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'You are not allowed to comment in this plan.',
            ], 403);
        }

        $validated = $request->validate([
            'content' => [
                'required',
                'string',
                'max:2000',
            ],
        ]);

        $content = trim(
            $validated['content']
        );

        if ($content === '') {
            return response()->json([
                'success0',
            ],
            422);
        }

        $content = trim(
            $validated['content']
        );

        if ($content === '') {
            return response()->json([
                'success' => false,
                'message' =>
                    'Please write a comment.',
            ], 422);
        }

        $comment = DB::transaction(
            function () use (
                $post,
                $plan,
                $user,
                $content
            ): PlanPostComment {
                $comment =
                    PlanPostComment::create([
                        'plan_post_id' =>
                            $post->id,
                        'user_id' =>
                            $user->id,
                        'content' =>
                            $content,
                    ]);

                /*
                 * Notify the post owner only when
                 * another user comments.
                 */
                if (
                    (int) $post->user_id !==
                    (int) $user->id
                ) {
                    ActivityNotification::create([
                        'recipient_user_id' =>
                            $post->user_id,

                        'actor_user_id' =>
                            $user->id,

                        'type' =>
                            'post_comment',

                        'plan_id' =>
                            $plan->id,

                        'plan_post_id' =>
                            $post->id,

                        'plan_post_comment_id' =>
                            $comment->id,

                        'data' => [
                            'plan_title' =>
                                $plan->title,

                            'post_type' =>
                                $post->post_type,

                            'comment_preview' =>
                                mb_substr(
                                    $content,
                                    0,
                                    140
                                ),
                        ],

                        'read_at' => null,
                    ]);
                }

                return $comment;
            }
        );

        $comment->load([
            'user:id,name,username,email,profile_photo_path',
        ]);

        return response()->json([
            'success' => true,
            'message' =>
                'Comment added successfully.',
            'comment' =>
                $this->serializeComment(
                    $comment,
                    $user->id,
                    $plan->admin_id
                ),
            'comment_count' =>
                $post
                    ->comments()
                    ->count(),
        ], 201);
    }

    /*
     * Delete a comment.
     *
     * Allowed:
     * - comment owner
     * - plan admin
     */
    public function destroy(
        Request $request,
        PlanPostComment $comment
    ): JsonResponse {
        $comment->loadMissing([
            'post.plan',
        ]);

        $post = $comment->post;
        $plan = $post?->plan;
        $user = $request->user();

        if (
            $post === null ||
            $plan === null ||
            $plan->is_deleted
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Comment not found.',
            ], 404);
        }

        if (
            !$this->canAccessPlan(
                $plan,
                $user->id
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'You are not allowed to access this comment.',
            ], 403);
        }

        $isCommentOwner =
            (int) $comment->user_id ===
            (int) $user->id;

        $isPlanAdmin =
            (int) $plan->admin_id ===
            (int) $user->id;

        if (
            !$isCommentOwner &&
            !$isPlanAdmin
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'You are not allowed to delete this comment.',
            ], 403);
        }

        $postId = $post->id;

        /*
         * Related Activity notification is removed
         * automatically through the database cascade.
         */
        $comment->delete();

        return response()->json([
            'success' => true,
            'message' =>
                'Comment deleted successfully.',
            'comment_count' =>
                PlanPostComment::query()
                    ->where(
                        'plan_post_id',
                        $postId
                    )
                    ->count(),
        ]);
    }

    private function canAccessPlan(
        $plan,
        int $userId
    ): bool {
        if (
            (int) $plan->admin_id ===
            (int) $userId
        ) {
            return true;
        }

        return $plan
            ->members()
            ->where(
                'users.id',
                $userId
            )
            ->exists();
    }

    private function serializeComment(
        PlanPostComment $comment,
        int $currentUserId,
        int $planAdminId
    ): array {
        $commentUser = $comment->user;

        $isCommentOwner =
            (int) $comment->user_id ===
            (int) $currentUserId;

        $isPlanAdmin =
            (int) $planAdminId ===
            (int) $currentUserId;

        return [
            'id' =>
                (int) $comment->id,

            'plan_post_id' =>
                (int) $comment->plan_post_id,

            'user_id' =>
                (int) $comment->user_id,

            'content' =>
                (string) $comment->content,

            'created_at' =>
                optional(
                    $comment->created_at
                )->toISOString(),

            'updated_at' =>
                optional(
                    $comment->updated_at
                )->toISOString(),

            'is_comment_owner' =>
                $isCommentOwner,

            'can_delete' =>
                $isCommentOwner ||
                $isPlanAdmin,

            'user' => $commentUser === null
                ? null
                : [
                    'id' =>
                        (int) $commentUser->id,

                    'name' =>
                        (string) $commentUser->name,

                    'username' =>
                        $commentUser->username,

                    'email' =>
                        $commentUser->email,

                    'profile_photo_path' =>
                        $commentUser
                            ->profile_photo_path,
                ],
        ];
    }
}