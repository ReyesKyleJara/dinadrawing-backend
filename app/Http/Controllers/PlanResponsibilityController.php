<?php

namespace App\Http\Controllers;

use App\Models\ActivityNotification;
use App\Models\Plan;
use App\Models\PlanPost;
use App\Models\PlanResponsibilityAssignment;
use App\Models\PlanResponsibilityItem;
use App\Models\User;
use App\Services\ActivityNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlanResponsibilityController extends Controller
{
    public function store(Request $request, Plan $plan)
    {
        $user = $request->user();

        $this->ensurePlanExists($plan);
        $this->ensurePlanMember($plan, $user->id);

        $validated = $request->validate([
            'responsibility_title' => [
                'required',
                'string',
                'max:255',
            ],
            'responsibility_mode' => [
                'required',
                Rule::in([
                    'person_based',
                    'role_task_based',
                ]),
            ],
            'responsibility_allow_member_items' => [
                'nullable',
                'boolean',
            ],
            'responsibility_show_progress' => [
                'nullable',
                'boolean',
            ],

            'items' => [
                'required',
                'array',
                'min:1',
                'max:50',
            ],
            'items.*.title' => [
                'required',
                'string',
                'max:255',
            ],
            'items.*.member_user_id' => [
                'nullable',
                'integer',
            ],
            'items.*.is_manual' => [
                'nullable',
                'boolean',
            ],
            'items.*.contribution' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'items.*.slots' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],
            'items.*.preassigned_user_ids' => [
                'nullable',
                'array',
            ],
            'items.*.preassigned_user_ids.*' => [
                'integer',
                'distinct',
            ],
            'items.*.manual_preassigned_names' => [
                'nullable',
                'array',
            ],
            'items.*.manual_preassigned_names.*' => [
                'string',
                'max:255',
            ],
        ]);

        $title = trim(
            $validated['responsibility_title']
        );

        if ($title === '') {
            return response()->json([
                'message' => 'Please enter a title.',
            ], 422);
        }

        $post = DB::transaction(function () use (
            $validated,
            $plan,
            $user,
            $title
        ) {
            $post = PlanPost::create([
                'plan_id' => $plan->id,
                'user_id' => $user->id,
                'post_type' => 'responsibility',
                'content' => $title,

                'responsibility_title' => $title,
                'responsibility_mode' =>
                    $validated['responsibility_mode'],

                'responsibility_allow_member_items' =>
                    $validated[
                        'responsibility_allow_member_items'
                    ] ?? false,

                'responsibility_show_progress' =>
                    $validated[
                        'responsibility_show_progress'
                    ] ?? true,

                'responsibility_is_finalized' => false,
                'is_pinned' => false,
            ]);

            $this->createResponsibilityItems(
                $post,
                $plan,
                $validated['items'],
                (int) $user->id
            );

            return $post;
        });

        return response()->json([
            'message' =>
                'Responsibilities created successfully.',
            'post' => $this->formatResponsibilityPost(
                $post,
                $request
            ),
        ], 201);
    }

    public function update(
        Request $request,
        PlanPost $post
    ) {
        $user = $request->user();
        $plan = $this->getResponsibilityPlan($post);

        $this->ensurePlanMember($plan, $user->id);
        $this->ensureCanManage(
            $post,
            $plan,
            $user->id
        );

        $validated = $request->validate([
            'responsibility_title' => [
                'nullable',
                'string',
                'max:255',
            ],
            'responsibility_mode' => [
                'nullable',
                Rule::in([
                    'person_based',
                    'role_task_based',
                ]),
            ],
            'responsibility_allow_member_items' => [
                'nullable',
                'boolean',
            ],
            'responsibility_show_progress' => [
                'nullable',
                'boolean',
            ],

            'items' => [
                'nullable',
                'array',
                'min:1',
                'max:50',
            ],
            'items.*.id' => [
                'nullable',
                'integer',
            ],
            'items.*.title' => [
                'required_with:items',
                'string',
                'max:255',
            ],
            'items.*.member_user_id' => [
                'nullable',
                'integer',
            ],
            'items.*.is_manual' => [
                'nullable',
                'boolean',
            ],
            'items.*.contribution' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'items.*.slots' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],
        ]);

        if (
            array_key_exists(
                'responsibility_mode',
                $validated
            ) &&
            $validated['responsibility_mode'] !==
                $post->responsibility_mode
        ) {
            return response()->json([
                'message' =>
                    'The list style cannot be changed after creation.',
            ], 422);
        }

        DB::transaction(function () use (
            $validated,
            $post,
            $plan
        ) {
            $updates = [];

            if (
                array_key_exists(
                    'responsibility_title',
                    $validated
                )
            ) {
                $title = trim(
                    $validated['responsibility_title']
                );

                if ($title === '') {
                    throw ValidationException::withMessages([
                        'responsibility_title' =>
                            'Please enter a title.',
                    ]);
                }

                $updates['responsibility_title'] = $title;
                $updates['content'] = $title;
            }

            if (
                array_key_exists(
                    'responsibility_allow_member_items',
                    $validated
                )
            ) {
                $updates[
                    'responsibility_allow_member_items'
                ] = $validated[
                    'responsibility_allow_member_items'
                ];
            }

            if (
                array_key_exists(
                    'responsibility_show_progress',
                    $validated
                )
            ) {
                $updates[
                    'responsibility_show_progress'
                ] = $validated[
                    'responsibility_show_progress'
                ];
            }

            if (!empty($updates)) {
                $post->update($updates);
            }

            if (array_key_exists('items', $validated)) {
                $this->synchronizeResponsibilityItems(
                    $post,
                    $plan,
                    $validated['items']
                );
            }
        });

        $this->notifyResponsibilityParticipants(
            $post->fresh(),
            $plan,
            (int) $user->id,
            'responsibility_updated'
        );

        return response()->json([
            'message' =>
                'Responsibilities updated successfully.',
            'post' => $this->formatResponsibilityPost(
                $post->fresh(),
                $request
            ),
        ]);
    }

    public function toggleFinalized(
        Request $request,
        PlanPost $post
    ) {
        $user = $request->user();
        $plan = $this->getResponsibilityPlan($post);

        $this->ensurePlanMember($plan, $user->id);
        $this->ensureCanManage(
            $post,
            $plan,
            $user->id
        );

        $validated = $request->validate([
            'is_finalized' => [
                'required',
                'boolean',
            ],
        ]);

        $post->update([
            'responsibility_is_finalized' =>
                $validated['is_finalized'],
        ]);

        if ($post->responsibility_is_finalized) {
            ActivityNotifier::deleteForPostByTypes(
                (int) $post->id,
                [
                    'responsibility_assignment_pending',
                    'responsibility_direct_assigned',
                ]
            );

            $this->notifyResponsibilityParticipants(
                $post,
                $plan,
                (int) $user->id,
                'responsibility_finalized'
            );
        } else {
            $this->notifyResponsibilityParticipants(
                $post,
                $plan,
                (int) $user->id,
                'responsibility_reopened'
            );
        }

        return response()->json([
            'message' =>
                $post->responsibility_is_finalized
                    ? 'Responsibilities finalized successfully.'
                    : 'Responsibilities reopened successfully.',

            'post' => $this->formatResponsibilityPost(
                $post,
                $request
            ),
        ]);
    }

    public function addItem(
        Request $request,
        PlanPost $post
    ) {
        $user = $request->user();
        $plan = $this->getResponsibilityPlan($post);

        $this->ensurePlanMember($plan, $user->id);

        if ($post->responsibility_is_finalized) {
            return response()->json([
                'message' =>
                    'This responsibility list is finalized.',
            ], 422);
        }

        $canManage = $this->canManage(
            $post,
            $plan,
            $user->id
        );

        if (
            !$canManage &&
            !$post->responsibility_allow_member_items
        ) {
            return response()->json([
                'message' =>
                    'Members are not allowed to add items.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:255',
            ],
            'member_user_id' => [
                'nullable',
                'integer',
            ],
            'is_manual' => [
                'nullable',
                'boolean',
            ],
            'contribution' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'slots' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],
            'preassigned_user_ids' => [
                'nullable',
                'array',
            ],
            'preassigned_user_ids.*' => [
                'integer',
                'distinct',
            ],
            'manual_preassigned_names' => [
                'nullable',
                'array',
            ],
            'manual_preassigned_names.*' => [
                'string',
                'max:255',
            ],
        ]);

        if (
            !$canManage &&
            (
                !empty(
                    $validated[
                        'preassigned_user_ids'
                    ] ?? []
                ) ||
                !empty(
                    $validated[
                        'manual_preassigned_names'
                    ] ?? []
                )
            )
        ) {
            return response()->json([
                'message' =>
                    'Only the creator or plan admin can pre-assign people.',
            ], 403);
        }

        DB::transaction(function () use (
            $post,
            $plan,
            $validated,
            $user
        ) {
            $position =
                $post->responsibilityItems()->count();

            $this->createSingleResponsibilityItem(
                $post,
                $plan,
                $validated,
                $position,
                (int) $user->id
            );
        });

        ActivityNotifier::notifyPlan(
            plan: $plan,
            actorUserId: (int) $user->id,
            type: 'responsibility_item_added',
            data: [
                'activity_tab' => 'notifications',
                'requires_action' => false,
                'responsibility_title' => (string) (
                    $post->responsibility_title
                    ?? $post->content
                    ?? 'Who Does What'
                ),
                'item_title' => (string) $validated['title'],
            ],
            planPostId: (int) $post->id,
            excludeUserId: (int) $user->id,
        );

        return response()->json([
            'message' => 'Item added successfully.',
            'post' => $this->formatResponsibilityPost(
                $post,
                $request
            ),
        ], 201);
    }

    public function updateContribution(
        Request $request,
        PlanResponsibilityItem $item
    ) {
        $user = $request->user();

        $item->load('post.plan');

        $post = $item->post;
        $plan = $this->getResponsibilityPlan($post);

        $this->ensurePlanMember($plan, $user->id);

        if (
            $post->responsibility_mode !==
            'person_based'
        ) {
            return response()->json([
                'message' =>
                    'Contributions are only available in person-based lists.',
            ], 422);
        }

        if ($post->responsibility_is_finalized) {
            return response()->json([
                'message' =>
                    'This responsibility list is finalized.',
            ], 422);
        }

        $canManage = $this->canManage(
            $post,
            $plan,
            $user->id
        );

        $isOwnRow =
            $item->member_user_id !== null &&
            (int) $item->member_user_id ===
                (int) $user->id;

        /*
         * Manual names have no account, so any plan member
         * may fill them in on that person's behalf.
         */
        $canFillManualRow = (bool) $item->is_manual;

        if (
            !$canManage &&
            !$isOwnRow &&
            !$canFillManualRow
        ) {
            return response()->json([
                'message' =>
                    'You cannot edit this person’s entry.',
            ], 403);
        }

        $validated = $request->validate([
            'contribution' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $item->update([
            'contribution' => trim(
                (string) (
                    $validated['contribution'] ?? ''
                )
            ),
        ]);

        ActivityNotifier::resolveByKey(
            'responsibility:item:' . $item->id . ':direct',
            'responsibility_progress_updated_by_you',
            [
                'responsibility_title' => (string) (
                    $post->responsibility_title
                    ?? $post->content
                    ?? 'Who Does What'
                ),
                'item_title' => (string) $item->title,
            ],
            (int) $user->id,
            true
        );

        $this->notifyResponsibilityManagers(
            $post,
            $plan,
            (int) $user->id,
            'responsibility_progress_updated',
            [
                'item_title' => (string) $item->title,
                'contribution' => (string) $item->contribution,
            ]
        );

        return response()->json([
            'message' =>
                'Contribution updated successfully.',
            'post' => $this->formatResponsibilityPost(
                $post,
                $request
            ),
        ]);
    }

    public function claim(
        Request $request,
        PlanResponsibilityItem $item
    ) {
        $user = $request->user();

        $item->load([
            'post.plan',
            'assignments',
        ]);

        $post = $item->post;
        $plan = $this->getResponsibilityPlan($post);

        $this->ensurePlanMember($plan, $user->id);

        if (
            $post->responsibility_mode !==
            'role_task_based'
        ) {
            return response()->json([
                'message' =>
                    'Only roles or tasks can be claimed.',
            ], 422);
        }

        if ($post->responsibility_is_finalized) {
            return response()->json([
                'message' =>
                    'These responsibilities are finalized.',
            ], 422);
        }

        $existing = $item->assignments()
            ->where('user_id', $user->id)
            ->first();

        if (
            $existing &&
            $existing->source === 'preassigned' &&
            $existing->status === 'pending'
        ) {
            return response()->json([
                'message' =>
                    'Please accept or decline your pending assignment.',
            ], 422);
        }

        if (
            $existing &&
            $existing->status === 'accepted'
        ) {
            return response()->json([
                'message' =>
                    'You already have this role or task.',
            ], 422);
        }

        $reservedSlots = $item->assignments()
            ->whereIn('status', [
                'pending',
                'accepted',
            ])
            ->count();

        if ($reservedSlots >= $item->slots) {
            return response()->json([
                'message' =>
                    'All available slots are already filled.',
            ], 422);
        }

        if ($existing) {
            $existing->update([
                'status' => 'accepted',
                'source' => 'claimed',
                'assigned_by_user_id' => null,
                'manual_name' => null,
            ]);
        } else {
            PlanResponsibilityAssignment::create([
                'responsibility_item_id' => $item->id,
                'user_id' => $user->id,
                'assigned_by_user_id' => null,
                'manual_name' => null,
                'status' => 'accepted',
                'source' => 'claimed',
            ]);
        }

        $this->notifyResponsibilityManagers(
            $post,
            $plan,
            (int) $user->id,
            'responsibility_claimed',
            [
                'item_title' => (string) $item->title,
            ]
        );

        return response()->json([
            'message' =>
                'Responsibility claimed successfully.',
            'post' => $this->formatResponsibilityPost(
                $post,
                $request
            ),
        ]);
    }

    public function unclaim(
        Request $request,
        PlanResponsibilityItem $item
    ) {
        $user = $request->user();

        $item->load('post.plan');

        $post = $item->post;
        $plan = $this->getResponsibilityPlan($post);

        $this->ensurePlanMember($plan, $user->id);

        if ($post->responsibility_is_finalized) {
            return response()->json([
                'message' =>
                    'These responsibilities are finalized.',
            ], 422);
        }

        $assignment = $item->assignments()
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->first();

        if (!$assignment) {
            return response()->json([
                'message' =>
                    'You have not claimed this role or task.',
            ], 422);
        }

        if ($assignment->source === 'preassigned') {
            /*
             * Preserve the record as a declined
             * pre-assignment for activity/history later.
             */
            $assignment->update([
                'status' => 'declined',
            ]);
        } else {
            $assignment->delete();
        }

        $this->notifyResponsibilityManagers(
            $post,
            $plan,
            (int) $user->id,
            'responsibility_unclaimed',
            [
                'item_title' => (string) $item->title,
            ]
        );

        return response()->json([
            'message' =>
                'Responsibility unclaimed successfully.',
            'post' => $this->formatResponsibilityPost(
                $post,
                $request
            ),
        ]);
    }

    public function respond(
        Request $request,
        PlanResponsibilityItem $item
    ) {
        $user = $request->user();

        $item->load('post.plan');

        $post = $item->post;
        $plan = $this->getResponsibilityPlan($post);

        $this->ensurePlanMember($plan, $user->id);

        if ($post->responsibility_is_finalized) {
            return response()->json([
                'message' =>
                    'These responsibilities are finalized.',
            ], 422);
        }

        $validated = $request->validate([
            'response' => [
                'required',
                Rule::in([
                    'accepted',
                    'declined',
                ]),
            ],
        ]);

        $assignment = $item->assignments()
            ->where('user_id', $user->id)
            ->where('source', 'preassigned')
            ->where('status', 'pending')
            ->first();

        if (!$assignment) {
            return response()->json([
                'message' =>
                    'No pending pre-assignment was found.',
            ], 422);
        }

        $responseValue = (string) $validated['response'];

        DB::transaction(function () use (
            $assignment,
            $responseValue,
            $user,
            $item,
            $post,
            $plan
        ): void {
            $assignment->update([
                'status' => $responseValue,
            ]);

            $this->resolvePendingAssignmentNotification(
                $assignment,
                $responseValue
            );

            $recipientUserId =
                $assignment->assigned_by_user_id
                ?? $post->user_id
                ?? $plan->admin_id;

            if (
                $recipientUserId !== null &&
                (int) $recipientUserId !== (int) $user->id
            ) {
                ActivityNotification::create([
                    'recipient_user_id' =>
                        (int) $recipientUserId,
                    'actor_user_id' =>
                        (int) $user->id,
                    'type' =>
                        $responseValue === 'accepted'
                            ? 'responsibility_assignment_accepted'
                            : 'responsibility_assignment_declined',
                    'plan_id' =>
                        (int) $plan->id,
                    'plan_post_id' =>
                        (int) $post->id,
                    'plan_post_comment_id' => null,
                    'data' => [
                        'activity_tab' => 'notifications',
                        'requires_action' => false,
                        'responsibility_item_id' =>
                            (int) $item->id,
                        'assignment_id' =>
                            (int) $assignment->id,
                        'responsibility_title' =>
                            (string) (
                                $post->responsibility_title
                                ?? $post->content
                                ?? 'Responsibilities'
                            ),
                        'item_title' =>
                            (string) $item->title,
                        'response' => $responseValue,
                        'can_reassign' =>
                            $responseValue === 'declined',
                    ],
                    'read_at' => null,
                ]);
            }
        });

        return response()->json([
            'message' =>
                $responseValue === 'accepted'
                    ? 'Assignment accepted successfully.'
                    : 'Assignment declined successfully.',

            'post' => $this->formatResponsibilityPost(
                $post,
                $request
            ),
        ]);
    }

    public function preassign(
        Request $request,
        PlanResponsibilityItem $item
    ) {
        $user = $request->user();

        $item->load([
            'post.plan',
            'assignments',
        ]);

        $post = $item->post;
        $plan = $this->getResponsibilityPlan($post);

        $this->ensurePlanMember($plan, $user->id);
        $this->ensureCanManage(
            $post,
            $plan,
            $user->id
        );

        if (
            $post->responsibility_mode !==
            'role_task_based'
        ) {
            return response()->json([
                'message' =>
                    'People can only be pre-assigned to roles or tasks.',
            ], 422);
        }

        if ($post->responsibility_is_finalized) {
            return response()->json([
                'message' =>
                    'These responsibilities are finalized.',
            ], 422);
        }

        $validated = $request->validate([
            'user_id' => [
                'nullable',
                'integer',
                'required_without:manual_name',
            ],
            'manual_name' => [
                'nullable',
                'string',
                'max:255',
                'required_without:user_id',
            ],
        ]);

        $reservedSlots = $item->assignments()
            ->whereIn('status', [
                'pending',
                'accepted',
            ])
            ->count();

        if ($reservedSlots >= $item->slots) {
            return response()->json([
                'message' =>
                    'All available slots are already filled.',
            ], 422);
        }

        if (!empty($validated['user_id'])) {
            $assignedUser = $this->findPlanParticipant(
                $plan,
                (int) $validated['user_id']
            );

            if (!$assignedUser) {
                return response()->json([
                    'message' =>
                        'The selected user is not a plan member.',
                ], 422);
            }

            $existing = $item->assignments()
                ->where(
                    'user_id',
                    $assignedUser->id
                )
                ->first();

            if (
                $existing &&
                in_array(
                    $existing->status,
                    ['pending', 'accepted'],
                    true
                )
            ) {
                return response()->json([
                    'message' =>
                        'This member is already assigned.',
                ], 422);
            }

            DB::transaction(
                function () use (
                    $existing,
                    $item,
                    $assignedUser,
                    $user,
                    $post,
                    $plan
                ): void {
                    if ($existing) {
                        $existing->update([
                            'status' => 'pending',
                            'source' => 'preassigned',
                            'assigned_by_user_id' =>
                                (int) $user->id,
                            'manual_name' => null,
                        ]);

                        $assignment = $existing->fresh();
                    } else {
                        $assignment =
                            PlanResponsibilityAssignment::create([
                                'responsibility_item_id' =>
                                    $item->id,
                                'user_id' =>
                                    $assignedUser->id,
                                'assigned_by_user_id' =>
                                    (int) $user->id,
                                'manual_name' => null,
                                'status' => 'pending',
                                'source' => 'preassigned',
                            ]);
                    }

                    $this->createPendingAssignmentNotification(
                        $assignment,
                        $item,
                        $post,
                        $plan,
                        (int) $user->id
                    );
                }
            );
        } else {
            $manualName = trim(
                (string) $validated['manual_name']
            );

            if ($manualName === '') {
                return response()->json([
                    'message' =>
                        'Please enter a person’s name.',
                ], 422);
            }

            $alreadyExists = $item->assignments()
                ->whereNull('user_id')
                ->whereRaw(
                    'LOWER(manual_name) = ?',
                    [strtolower($manualName)]
                )
                ->exists();

            if ($alreadyExists) {
                return response()->json([
                    'message' =>
                        'This person is already assigned.',
                ], 422);
            }

            /*
             * Manual people have no account to accept or decline,
             * so their assignment is accepted immediately.
             */
            PlanResponsibilityAssignment::create([
                'responsibility_item_id' =>
                    $item->id,
                'user_id' => null,
                'assigned_by_user_id' =>
                    (int) $user->id,
                'manual_name' => $manualName,
                'status' => 'accepted',
                'source' => 'preassigned',
            ]);
        }

        return response()->json([
            'message' =>
                'Person pre-assigned successfully.',
            'post' => $this->formatResponsibilityPost(
                $post,
                $request
            ),
        ]);
    }

    public function removePreassignment(
        Request $request,
        PlanResponsibilityItem $item,
        PlanResponsibilityAssignment $assignment
    ) {
        $user = $request->user();

        $item->load('post.plan');

        $post = $item->post;
        $plan = $this->getResponsibilityPlan($post);

        $this->ensurePlanMember($plan, $user->id);
        $this->ensureCanManage(
            $post,
            $plan,
            $user->id
        );

        if (
            (int) $assignment->responsibility_item_id !==
            (int) $item->id
        ) {
            return response()->json([
                'message' =>
                    'The assignment does not belong to this item.',
            ], 422);
        }

        if ($assignment->source !== 'preassigned') {
            return response()->json([
                'message' =>
                    'Only pre-assignments can be removed by the creator.',
            ], 422);
        }

        DB::transaction(function () use (
            $assignment,
            $user
        ): void {
            $this->resolvePendingAssignmentNotification(
                $assignment,
                'removed',
                (int) $user->id
            );

            $assignment->delete();
        });

        return response()->json([
            'message' =>
                'Pre-assignment removed successfully.',
            'post' => $this->formatResponsibilityPost(
                $post,
                $request
            ),
        ]);
    }

    private function createResponsibilityItems(
        PlanPost $post,
        Plan $plan,
        array $items,
        int $assignedByUserId
    ): void {
        $seenMemberIds = [];
        $seenNames = [];
        $seenRoleTaskTitles = [];

        foreach ($items as $position => $itemData) {
            if (
                $post->responsibility_mode ===
                'person_based'
            ) {
                $memberUserId =
                    isset($itemData['member_user_id'])
                        ? (int) $itemData[
                            'member_user_id'
                        ]
                        : null;

                if ($memberUserId !== null) {
                    if (
                        in_array(
                            $memberUserId,
                            $seenMemberIds,
                            true
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'items' =>
                                'A plan member cannot appear more than once.',
                        ]);
                    }

                    $seenMemberIds[] = $memberUserId;
                } else {
                    $cleanName = strtolower(
                        trim(
                            (string) $itemData['title']
                        )
                    );

                    if (
                        in_array(
                            $cleanName,
                            $seenNames,
                            true
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'items' =>
                                'A person cannot appear more than once.',
                        ]);
                    }

                    $seenNames[] = $cleanName;
                }
            } else {
                $cleanTitle = strtolower(
                    trim((string) $itemData['title'])
                );

                if (
                    in_array(
                        $cleanTitle,
                        $seenRoleTaskTitles,
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'items' =>
                            'Role or task names must be unique.',
                    ]);
                }

                $seenRoleTaskTitles[] = $cleanTitle;
            }

            $this->createSingleResponsibilityItem(
                $post,
                $plan,
                $itemData,
                $position,
                $assignedByUserId
            );
        }
    }

    private function createSingleResponsibilityItem(
        PlanPost $post,
        Plan $plan,
        array $itemData,
        int $position,
        int $assignedByUserId
    ): PlanResponsibilityItem {
        if (
            $post->responsibility_mode ===
            'person_based'
        ) {
            $memberUserId =
                isset($itemData['member_user_id'])
                    ? (int) $itemData['member_user_id']
                    : null;

            if ($memberUserId !== null) {
                $member = $this->findPlanParticipant(
                    $plan,
                    $memberUserId
                );

                if (!$member) {
                    throw ValidationException::withMessages([
                        'items' =>
                            'One of the selected users is not a plan member.',
                    ]);
                }

                $duplicate = $post
                    ->responsibilityItems()
                    ->where(
                        'member_user_id',
                        $member->id
                    )
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'items' =>
                            'This plan member is already listed.',
                    ]);
                }

                $createdItem = PlanResponsibilityItem::create([
                    'plan_post_id' => $post->id,
                    'member_user_id' => $member->id,
                    'title' => $member->name,
                    'is_manual' => false,
                    'contribution' => trim(
                        (string) (
                            $itemData['contribution'] ?? ''
                        )
                    ),
                    'slots' => 1,
                    'position' => $position,
                ]);

                ActivityNotifier::notifyUser(
                    recipientUserId: (int) $member->id,
                    actorUserId: (int) $post->user_id,
                    type: 'responsibility_direct_assigned',
                    planId: (int) $plan->id,
                    planPostId: (int) $post->id,
                    data: [
                        'activity_tab' => 'action_required',
                        'requires_action' => true,
                        'action' => 'view_responsibility',
                        'responsibility_item_id' =>
                            (int) $createdItem->id,
                        'responsibility_title' =>
                            (string) (
                                $post->responsibility_title
                                ?? $post->content
                                ?? 'Who Does What'
                            ),
                        'item_title' =>
                            (string) $createdItem->title,
                    ],
                    notificationKey:
                        'responsibility:item:' .
                        $createdItem->id . ':direct',
                    replaceExisting: true,
                );

                return $createdItem;
            }

            $manualName = trim(
                (string) $itemData['title']
            );

            if ($manualName === '') {
                throw ValidationException::withMessages([
                    'items' =>
                        'Please enter a person’s name.',
                ]);
            }

            $duplicate = $post
                ->responsibilityItems()
                ->whereNull('member_user_id')
                ->whereRaw(
                    'LOWER(title) = ?',
                    [strtolower($manualName)]
                )
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'items' =>
                        'This person is already listed.',
                ]);
            }

            return PlanResponsibilityItem::create([
                'plan_post_id' => $post->id,
                'member_user_id' => null,
                'title' => $manualName,
                'is_manual' => true,
                'contribution' => trim(
                    (string) (
                        $itemData['contribution'] ?? ''
                    )
                ),
                'slots' => 1,
                'position' => $position,
            ]);
        }

        $roleTaskTitle = trim(
            (string) $itemData['title']
        );

        if ($roleTaskTitle === '') {
            throw ValidationException::withMessages([
                'items' =>
                    'Please enter a role or task.',
            ]);
        }

        $slots = max(
            1,
            (int) ($itemData['slots'] ?? 1)
        );

        $userIds = collect(
            $itemData['preassigned_user_ids'] ?? []
        )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $manualNames = collect(
            $itemData[
                'manual_preassigned_names'
            ] ?? []
        )
            ->map(
                fn ($name) =>
                    trim((string) $name)
            )
            ->filter(
                fn ($name) => $name !== ''
            )
            ->map(
                fn ($name) => [
                    'original' => $name,
                    'lower' => strtolower($name),
                ]
            )
            ->unique('lower')
            ->values();

        if (
            $userIds->count() +
                $manualNames->count() >
            $slots
        ) {
            throw ValidationException::withMessages([
                'items' =>
                    'Pre-assigned people cannot exceed the available slots.',
            ]);
        }

        $item = PlanResponsibilityItem::create([
            'plan_post_id' => $post->id,
            'member_user_id' => null,
            'title' => $roleTaskTitle,
            'is_manual' => false,
            'contribution' => null,
            'slots' => $slots,
            'position' => $position,
        ]);

        foreach ($userIds as $userId) {
            $member = $this->findPlanParticipant(
                $plan,
                $userId
            );

            if (!$member) {
                throw ValidationException::withMessages([
                    'items' =>
                        'A pre-assigned user is not a plan member.',
                ]);
            }

            $assignment =
                PlanResponsibilityAssignment::create([
                    'responsibility_item_id' =>
                        $item->id,
                    'user_id' => $member->id,
                    'assigned_by_user_id' =>
                        $assignedByUserId,
                    'manual_name' => null,
                    'status' => 'pending',
                    'source' => 'preassigned',
                ]);

            $this->createPendingAssignmentNotification(
                $assignment,
                $item,
                $post,
                $plan,
                $assignedByUserId
            );
        }

        foreach ($manualNames as $name) {
            PlanResponsibilityAssignment::create([
                'responsibility_item_id' =>
                    $item->id,
                'user_id' => null,
                'assigned_by_user_id' =>
                    $assignedByUserId,
                'manual_name' =>
                    $name['original'],
                'status' => 'accepted',
                'source' => 'preassigned',
            ]);
        }

        return $item;
    }

    private function synchronizeResponsibilityItems(
        PlanPost $post,
        Plan $plan,
        array $items
    ): void {
        $existingItems = $post
            ->responsibilityItems()
            ->with('assignments')
            ->get()
            ->keyBy('id');

        $keptItemIds = [];
        $seenMemberIds = [];
        $seenNames = [];
        $seenRoleTaskTitles = [];

        foreach ($items as $position => $itemData) {
            $itemId =
                isset($itemData['id'])
                    ? (int) $itemData['id']
                    : null;

            $existingItem =
                $itemId !== null
                    ? $existingItems->get($itemId)
                    : null;

            if (
                $itemId !== null &&
                !$existingItem
            ) {
                throw ValidationException::withMessages([
                    'items' =>
                        'An item does not belong to this responsibility post.',
                ]);
            }

            if (
                $post->responsibility_mode ===
                'person_based'
            ) {
                $memberUserId =
                    isset($itemData['member_user_id'])
                        ? (int) $itemData[
                            'member_user_id'
                        ]
                        : null;

                if ($memberUserId !== null) {
                    if (
                        in_array(
                            $memberUserId,
                            $seenMemberIds,
                            true
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'items' =>
                                'A plan member cannot appear more than once.',
                        ]);
                    }

                    $seenMemberIds[] = $memberUserId;

                    $member = $this->findPlanParticipant(
                        $plan,
                        $memberUserId
                    );

                    if (!$member) {
                        throw ValidationException::withMessages([
                            'items' =>
                                'One selected user is not a plan member.',
                        ]);
                    }

                    $data = [
                        'member_user_id' =>
                            $member->id,
                        'title' =>
                            $member->name,
                        'is_manual' => false,
                        'contribution' => trim(
                            (string) (
                                $itemData[
                                    'contribution'
                                ] ?? ''
                            )
                        ),
                        'slots' => 1,
                        'position' => $position,
                    ];
                } else {
                    $manualName = trim(
                        (string) $itemData['title']
                    );

                    if ($manualName === '') {
                        throw ValidationException::withMessages([
                            'items' =>
                                'Please enter a person’s name.',
                        ]);
                    }

                    $cleanName = strtolower(
                        $manualName
                    );

                    if (
                        in_array(
                            $cleanName,
                            $seenNames,
                            true
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'items' =>
                                'A person cannot appear more than once.',
                        ]);
                    }

                    $seenNames[] = $cleanName;

                    $data = [
                        'member_user_id' => null,
                        'title' => $manualName,
                        'is_manual' => true,
                        'contribution' => trim(
                            (string) (
                                $itemData[
                                    'contribution'
                                ] ?? ''
                            )
                        ),
                        'slots' => 1,
                        'position' => $position,
                    ];
                }
            } else {
                $roleTaskTitle = trim(
                    (string) $itemData['title']
                );

                if ($roleTaskTitle === '') {
                    throw ValidationException::withMessages([
                        'items' =>
                            'Please enter a role or task.',
                    ]);
                }

                $cleanTitle = strtolower(
                    $roleTaskTitle
                );

                if (
                    in_array(
                        $cleanTitle,
                        $seenRoleTaskTitles,
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'items' =>
                            'Role or task names must be unique.',
                    ]);
                }

                $seenRoleTaskTitles[] = $cleanTitle;

                $slots = max(
                    1,
                    (int) (
                        $itemData['slots'] ?? 1
                    )
                );

                if ($existingItem) {
                    $activeAssignments =
                        $existingItem
                            ->assignments
                            ->whereIn(
                                'status',
                                [
                                    'pending',
                                    'accepted',
                                ]
                            )
                            ->count();

                    if ($slots < $activeAssignments) {
                        throw ValidationException::withMessages([
                            'items' =>
                                'Available slots cannot be lower than the number of assigned people.',
                        ]);
                    }
                }

                $data = [
                    'member_user_id' => null,
                    'title' => $roleTaskTitle,
                    'is_manual' => false,
                    'contribution' => null,
                    'slots' => $slots,
                    'position' => $position,
                ];
            }

            if ($existingItem) {
                $existingItem->update($data);
                $keptItemIds[] = $existingItem->id;
            } else {
                $newItem =
                    PlanResponsibilityItem::create([
                        'plan_post_id' => $post->id,
                        ...$data,
                    ]);

                $keptItemIds[] = $newItem->id;
            }
        }

        $itemsToDelete = $existingItems
            ->reject(
                fn ($item) =>
                    in_array(
                        $item->id,
                        $keptItemIds,
                        true
                    )
            );

        foreach ($itemsToDelete as $item) {
            $hasContribution =
                trim(
                    (string) $item->contribution
                ) !== '';

            $hasAssignments =
                $item->assignments->isNotEmpty();

            if (
                $hasContribution ||
                $hasAssignments
            ) {
                throw ValidationException::withMessages([
                    'items' =>
                        'Items with contributions or assigned people cannot be removed.',
                ]);
            }

            $item->delete();
        }
    }

    private function createPendingAssignmentNotification(
        PlanResponsibilityAssignment $assignment,
        PlanResponsibilityItem $item,
        PlanPost $post,
        Plan $plan,
        int $actorUserId
    ): void {
        if (
            $assignment->user_id === null ||
            (int) $assignment->user_id === $actorUserId
        ) {
            return;
        }

        $this->deleteAssignmentNotifications(
            $assignment,
            [
                'responsibility_assignment_pending',
                'responsibility_assignment_accepted_by_you',
                'responsibility_assignment_declined_by_you',
                'responsibility_assignment_removed',
            ]
        );

        ActivityNotification::create([
            'recipient_user_id' =>
                (int) $assignment->user_id,
            'actor_user_id' => $actorUserId,
            'type' =>
                'responsibility_assignment_pending',
            'plan_id' => (int) $plan->id,
            'plan_post_id' => (int) $post->id,
            'plan_post_comment_id' => null,
            'data' => [
                'activity_tab' => 'action_required',
                'requires_action' => true,
                'action' => 'review_assignment',
                'responsibility_item_id' =>
                    (int) $item->id,
                'assignment_id' =>
                    (int) $assignment->id,
                'responsibility_title' =>
                    (string) (
                        $post->responsibility_title
                        ?? $post->content
                        ?? 'Responsibilities'
                    ),
                'item_title' =>
                    (string) $item->title,
            ],
            'read_at' => null,
        ]);
    }

    private function resolvePendingAssignmentNotification(
        PlanResponsibilityAssignment $assignment,
        string $resolution,
        ?int $actorUserId = null
    ): void {
        if ($assignment->user_id === null) {
            return;
        }

        $notifications = ActivityNotification::query()
            ->where(
                'recipient_user_id',
                (int) $assignment->user_id
            )
            ->where(
                'type',
                'responsibility_assignment_pending'
            )
            ->get();

        foreach ($notifications as $notification) {
            $data = is_array($notification->data)
                ? $notification->data
                : [];

            if (
                (int) ($data['assignment_id'] ?? 0) !==
                (int) $assignment->id
            ) {
                continue;
            }

            $resolvedType = match ($resolution) {
                'accepted' =>
                    'responsibility_assignment_accepted_by_you',
                'declined' =>
                    'responsibility_assignment_declined_by_you',
                'removed' =>
                    'responsibility_assignment_removed',
                default =>
                    'responsibility_assignment_resolved',
            };

            $notification->update([
                'actor_user_id' =>
                    $actorUserId
                    ?? $notification->actor_user_id,
                'type' => $resolvedType,
                'data' => [
                    ...$data,
                    'activity_tab' => 'notifications',
                    'requires_action' => false,
                    'resolution' => $resolution,
                ],
                'read_at' =>
                    $notification->read_at ?? now(),
            ]);
        }
    }

    private function deleteAssignmentNotifications(
        PlanResponsibilityAssignment $assignment,
        array $types
    ): void {
        if ($assignment->user_id === null) {
            return;
        }

        $notifications = ActivityNotification::query()
            ->where(
                'recipient_user_id',
                (int) $assignment->user_id
            )
            ->whereIn('type', $types)
            ->get();

        foreach ($notifications as $notification) {
            $data = is_array($notification->data)
                ? $notification->data
                : [];

            if (
                (int) ($data['assignment_id'] ?? 0) ===
                (int) $assignment->id
            ) {
                $notification->delete();
            }
        }
    }

    private function notifyResponsibilityManagers(
        PlanPost $post,
        Plan $plan,
        int $actorUserId,
        string $type,
        array $extraData = []
    ): void {
        $recipientIds = collect([
            (int) $plan->admin_id,
            (int) $post->user_id,
        ])->reject(
            fn ($id) => $id <= 0 || $id === $actorUserId
        )->unique();

        ActivityNotifier::notifyUsers(
            recipientUserIds: $recipientIds,
            actorUserId: $actorUserId,
            type: $type,
            planId: (int) $plan->id,
            planPostId: (int) $post->id,
            data: [
                'activity_tab' => 'notifications',
                'requires_action' => false,
                'responsibility_title' => (string) (
                    $post->responsibility_title
                    ?? $post->content
                    ?? 'Who Does What'
                ),
                ...$extraData,
            ],
        );
    }

    private function notifyResponsibilityParticipants(
        PlanPost $post,
        Plan $plan,
        int $actorUserId,
        string $type
    ): void {
        $post->loadMissing([
            'responsibilityItems.member',
            'responsibilityItems.assignments',
        ]);

        $recipientIds = collect();

        foreach ($post->responsibilityItems as $item) {
            if ($item->member_user_id !== null) {
                $recipientIds->push((int) $item->member_user_id);
            }

            foreach ($item->assignments as $assignment) {
                if (
                    $assignment->user_id !== null &&
                    in_array($assignment->status, ['pending', 'accepted'], true)
                ) {
                    $recipientIds->push((int) $assignment->user_id);
                }
            }
        }

        ActivityNotifier::notifyUsers(
            recipientUserIds: $recipientIds
                ->reject(fn ($id) => $id === $actorUserId)
                ->unique(),
            actorUserId: $actorUserId,
            type: $type,
            planId: (int) $plan->id,
            planPostId: (int) $post->id,
            data: [
                'activity_tab' => 'notifications',
                'requires_action' => false,
                'responsibility_title' => (string) (
                    $post->responsibility_title
                    ?? $post->content
                    ?? 'Who Does What'
                ),
            ],
            notificationKey: 'responsibility:' . $post->id . ':' . $type,
            replaceExisting: true,
        );
    }

    private function formatResponsibilityPost(
        PlanPost $post,
        Request $request
    ): PlanPost {
        $post->load([
            'user:id,name,username,email',

            'responsibilityItems.member:id,name,username,email',

            'responsibilityItems.assignments.user:id,name,username,email',
        ]);

        $user = $request->user();
        $plan = $post->plan;

        $isPlanAdmin =
            (int) $plan->admin_id ===
            (int) $user->id;

        $isPostOwner =
            (int) $post->user_id ===
            (int) $user->id;

        $canManage =
            $isPlanAdmin ||
            $isPostOwner;

        $totalCount = 0;
        $filledCount = 0;

        foreach (
            $post->responsibilityItems
            as $item
        ) {
            $acceptedCount =
                $item->assignments
                    ->where('status', 'accepted')
                    ->count();

            $pendingCount =
                $item->assignments
                    ->where('status', 'pending')
                    ->count();

            $currentUserAssignment =
                $item->assignments
                    ->firstWhere(
                        'user_id',
                        $user->id
                    );

            foreach (
                $item->assignments
                as $assignment
            ) {
                $assignment->display_name =
                    $assignment->user?->name
                    ?? $assignment->manual_name;

                $assignment->username_value =
                    $assignment->user?->username;

                $assignment->is_current_user =
                    (int) $assignment->user_id ===
                    (int) $user->id;
            }

            $item->member_display_name =
                $item->member?->name
                ?? $item->title;

            $item->member_username =
                $item->member?->username;

            $item->is_current_user_member =
                $item->member_user_id !== null &&
                (int) $item->member_user_id ===
                    (int) $user->id;

            $item->accepted_count =
                $acceptedCount;

            $item->pending_count =
                $pendingCount;

            $item->reserved_count =
                $acceptedCount +
                $pendingCount;

            $item->current_user_assignment =
                $currentUserAssignment
                    ? [
                        'id' =>
                            $currentUserAssignment->id,
                        'status' =>
                            $currentUserAssignment->status,
                        'source' =>
                            $currentUserAssignment->source,
                    ]
                    : null;

            $item->can_current_user_fill_contribution =
                !$post->responsibility_is_finalized &&
                (
                    $canManage ||
                    $item->is_manual ||
                    (
                        $item->member_user_id !== null &&
                        (int) $item->member_user_id ===
                            (int) $user->id
                    )
                );

            $item->can_current_user_claim =
                !$post->responsibility_is_finalized &&
                $post->responsibility_mode ===
                    'role_task_based' &&
                !$currentUserAssignment &&
                (
                    $acceptedCount +
                    $pendingCount
                ) < $item->slots;

            if (
                $post->responsibility_mode ===
                'person_based'
            ) {
                $totalCount++;

                if (
                    trim(
                        (string) $item->contribution
                    ) !== ''
                ) {
                    $filledCount++;
                }
            } else {
                $totalCount += $item->slots;
                $filledCount += $acceptedCount;
            }
        }

        $post->is_pinned_value =
            (bool) $post->is_pinned;

        $post->is_plan_admin =
            $isPlanAdmin;

        $post->is_post_owner =
            $isPostOwner;

        $post->can_pin_post =
            $isPlanAdmin;

        $post->can_manage_responsibility =
            $canManage;

        $post->can_finalize_responsibility =
            $canManage;

        $post->can_add_responsibility_items =
            !$post->responsibility_is_finalized &&
            (
                $canManage ||
                $post->
                    responsibility_allow_member_items
            );

        $post->can_delete_post =
            $canManage;

        $post->responsibility_total_count =
            $totalCount;

        $post->responsibility_filled_count =
            $filledCount;

        return $post;
    }

    private function getResponsibilityPlan(
        PlanPost $post
    ): Plan {
        if (
            $post->post_type !==
            'responsibility'
        ) {
            abort(
                422,
                'This post is not a responsibility post.'
            );
        }

        $plan = $post->plan;

        if (!$plan || $plan->is_deleted) {
            abort(404, 'Plan not found.');
        }

        return $plan;
    }

    private function ensurePlanExists(
        Plan $plan
    ): void {
        if ($plan->is_deleted) {
            abort(404, 'Plan not found.');
        }
    }

    private function ensurePlanMember(
        Plan $plan,
        int $userId
    ): void {
        if (
            (int) $plan->admin_id ===
            (int) $userId
        ) {
            return;
        }

        $isMember = $plan->members()
            ->where('users.id', $userId)
            ->exists();

        if (!$isMember) {
            abort(
                403,
                'You are not a member of this plan.'
            );
        }
    }

    private function findPlanParticipant(
        Plan $plan,
        int $userId
    ): ?User {
        if (
            (int) $plan->admin_id ===
            (int) $userId
        ) {
            return User::find($userId);
        }

        return $plan->members()
            ->where('users.id', $userId)
            ->first();
    }

    private function canManage(
        PlanPost $post,
        Plan $plan,
        int $userId
    ): bool {
        return
            (int) $plan->admin_id ===
                (int) $userId ||
            (int) $post->user_id ===
                (int) $userId;
    }

    private function ensureCanManage(
        PlanPost $post,
        Plan $plan,
        int $userId
    ): void {
        if (
            !$this->canManage(
                $post,
                $plan,
                $userId
            )
        ) {
            abort(
                403,
                'You are not allowed to manage these responsibilities.'
            );
        }
    }
}