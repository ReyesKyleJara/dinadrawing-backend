<?php

namespace App\Http\Controllers;

use App\Models\BudgetAllocation;
use App\Models\Plan;
use App\Models\PlanBudget;
use App\Services\ActivityNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanBudgetController extends Controller
{
    public function show(
        Request $request,
        Plan $plan
    ): JsonResponse {
        $this->ensureCanViewPlan($request, $plan);

        $budget = $plan->budget;

        if ($budget === null) {
            return response()->json([
                'success' => true,
                'budget' => null,
                'can_manage_budget' => $this->isPlanAdmin(
                    $request,
                    $plan
                ),
                'available_members' => $this->serializePlanMembers(
                    $plan
                ),
            ]);
        }

        return response()->json([
            'success' => true,
            'budget' => $this->serializeBudget(
                $request,
                $plan,
                $budget
            ),
            'available_members' => $this->serializePlanMembers(
                $plan
            ),
        ]);
    }

    public function store(
        Request $request,
        Plan $plan
    ): JsonResponse {
        $this->ensurePlanAdmin($request, $plan);

        if ($plan->budget()->exists()) {
            return response()->json([
                'success' => false,
                'message' =>
                    'A budget plan already exists for this plan.',
            ], 409);
        }

        $budget = $this->saveBudget(
            request: $request,
            plan: $plan,
            budget: null,
        );

        $this->syncContributionNotifications(
            $plan,
            $budget,
            (int) $request->user()->id,
            []
        );

        return response()->json([
            'success' => true,
            'message' => 'Budget plan created.',
            'budget' => $this->serializeBudget(
                $request,
                $plan,
                $budget
            ),
        ], 201);
    }

    public function update(
        Request $request,
        Plan $plan
    ): JsonResponse {
        $this->ensurePlanAdmin($request, $plan);

        $budget = $plan->budget;

        if ($budget === null) {
            return response()->json([
                'success' => false,
                'message' =>
                    'No budget plan exists for this plan.',
            ], 404);
        }

        $oldAllocations = $budget->allocations()
            ->get()
            ->mapWithKeys(
                fn (BudgetAllocation $allocation) => [
                    (int) $allocation->id => [
                        'id' => (int) $allocation->id,
                        'user_id' => $allocation->user_id === null
                            ? null
                            : (int) $allocation->user_id,
                        'planned_share' => (float) $allocation->planned_share,
                    ],
                ]
            )
            ->all();

        $budget = $this->saveBudget(
            request: $request,
            plan: $plan,
            budget: $budget,
        );

        $this->syncContributionNotifications(
            $plan,
            $budget,
            (int) $request->user()->id,
            $oldAllocations
        );

        return response()->json([
            'success' => true,
            'message' => 'Budget plan updated.',
            'budget' => $this->serializeBudget(
                $request,
                $plan,
                $budget
            ),
        ]);
    }

    public function updateSettings(
        Request $request,
        Plan $plan
    ): JsonResponse {
        $this->ensurePlanAdmin($request, $plan);

        $budget = $plan->budget;

        if ($budget === null) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Create the budget plan before updating contribution settings.',
            ], 404);
        }

        $validated = $request->validate([
            'contribution_tracking_enabled' => [
                'sometimes',
                'boolean',
            ],
            'allow_member_mark_paid' => [
                'sometimes',
                'boolean',
            ],
            'show_status_to_members' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $budget->fill($validated);

        $budget->updated_by =
            $request->user()->id;

        $budget->save();

        if ($budget->contribution_tracking_enabled) {
            $this->syncContributionNotifications(
                $plan,
                $budget->fresh(),
                (int) $request->user()->id,
                []
            );
        } else {
            ActivityNotifier::deleteByTypesForPlan(
                (int) $plan->id,
                [
                    'budget_contribution_required',
                    'budget_contribution_changed',
                    'budget_allocation_assigned',
                    'budget_allocation_changed',
                    'budget_payment_rejected',
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' =>
                'Contribution tracking settings updated.',
            'budget' => $this->serializeBudget(
                $request,
                $plan,
                $budget
            ),
        ]);
    }

    public function resolveReview(
        Request $request,
        Plan $plan
    ): JsonResponse {
        $this->ensurePlanAdmin($request, $plan);

        $budget = $plan->budget;

        if ($budget === null) {
            return response()->json([
                'success' => false,
                'message' =>
                    'No budget plan exists for this plan.',
            ], 404);
        }

        if (!$budget->needs_review) {
            return response()->json([
                'success' => false,
                'message' =>
                    'This budget does not currently need review.',
            ], 422);
        }

        $validated = $request->validate([
            'action' => [
                'required',
                'in:redistribute_equally,keep_unallocated',
            ],
        ]);

        DB::transaction(
            function () use (
                $request,
                $budget,
                $validated
            ): void {
                if (
                    $validated['action'] ===
                    'redistribute_equally'
                ) {
                    $activeAllocations = $budget
                        ->allocations()
                        ->where(
                            'is_former_member',
                            false
                        )
                        ->where(
                            'is_included',
                            true
                        )
                        ->orderBy('id')
                        ->get();

                    if ($activeAllocations->isEmpty()) {
                        throw ValidationException::withMessages([
                            'action' =>
                                'Include at least one active member before redistributing the budget.',
                        ]);
                    }

                    $totalEstimatedCents =
                        $this->toCents(
                            $budget->total_estimated
                        );

                    $baseShare = intdiv(
                        $totalEstimatedCents,
                        $activeAllocations->count()
                    );

                    $remainingCents =
                        $totalEstimatedCents %
                        $activeAllocations->count();

                    foreach (
                        $activeAllocations
                        as $index => $allocation
                    ) {
                        $newShareCents =
                            $baseShare +
                            (
                                $index < $remainingCents
                                    ? 1
                                    : 0
                            );

                        $shareChanged =
                            $this->toCents(
                                $allocation->planned_share
                            ) !== $newShareCents;

                        $payload = [
                            'planned_share' =>
                                $this->fromCents(
                                    $newShareCents
                                ),
                        ];

                        if ($shareChanged) {
                            $payload['is_paid'] = false;
                            $payload['paid_at'] = null;
                            $payload['marked_paid_by'] =
                                null;
                        }

                        $allocation->update($payload);
                    }

                    $budget->split_type = 'equal';
                } else {
                    /*
                     * Keep all current active shares as-is.
                     * The departed amount remains visible
                     * as Unallocated.
                     */
                    $budget->split_type = 'custom';
                }

                $reviewContext =
                    $budget->review_context ?? [];

                $reviewContext['resolved_action'] =
                    $validated['action'];

                $reviewContext['resolved_at'] =
                    now()->toISOString();

                $reviewContext['resolved_by'] = [
                    'id' =>
                        (int) $request->user()->id,
                    'name' =>
                        (string) $request->user()->name,
                ];

                $budget->needs_review = false;
                $budget->review_reason = null;
                $budget->review_context =
                    $reviewContext;
                $budget->reviewed_at = now();
                $budget->reviewed_by =
                    $request->user()->id;
                $budget->updated_by =
                    $request->user()->id;
                $budget->save();
            }
        );

        ActivityNotifier::deleteByKey(
            'budget:' . $budget->id . ':review',
            (int) $request->user()->id
        );

        $this->syncContributionNotifications(
            $plan,
            $budget->fresh(),
            (int) $request->user()->id,
            []
        );

        return response()->json([
            'success' => true,
            'message' =>
                $validated['action'] ===
                'redistribute_equally'
                    ? 'The departed share was redistributed equally.'
                    : 'The departed share remains unallocated.',
            'budget' => $this->serializeBudget(
                $request,
                $plan,
                $budget->fresh()
            ),
        ]);
    }

    public function setPaidStatus(
        Request $request,
        Plan $plan,
        BudgetAllocation $allocation
    ): JsonResponse {
        $this->ensureCanViewPlan($request, $plan);

        $budget = $plan->budget;

        if (
            $budget === null ||
            (int) $allocation->budget_id !==
                (int) $budget->id
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'This budget allocation does not belong to the selected plan.',
            ], 404);
        }

        if (
            !$budget->contribution_tracking_enabled
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Contribution tracking is currently disabled.',
            ], 422);
        }

        if ($allocation->is_former_member) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Former member contributions can no longer be edited.',
            ], 422);
        }

        if (!$allocation->is_included) {
            return response()->json([
                'success' => false,
                'message' =>
                    'People removed from the budget do not have a contribution status.',
            ], 422);
        }

        $userId = (int) $request->user()->id;

        $isAdmin = $this->isPlanAdmin(
            $request,
            $plan
        );

        $isOwnAllocation =
            $allocation->user_id !== null &&
            (int) $allocation->user_id === $userId;

        $memberMayUpdateOwn =
            $isOwnAllocation &&
            $budget->allow_member_mark_paid;

        if (!$isAdmin && !$memberMayUpdateOwn) {
            abort(
                403,
                'You are not allowed to update this contribution status.'
            );
        }

        $validated = $request->validate([
            'is_paid' => [
                'required',
                'boolean',
            ],
        ]);

        $isPaid = (bool) $validated['is_paid'];
        $wasPaid = (bool) $allocation->is_paid;

        $allocation->update([
            'is_paid' => $isPaid,
            'paid_at' => $isPaid
                ? now()
                : null,
            'marked_paid_by' => $isPaid
                ? $userId
                : null,
        ]);

        $amount = (float) $allocation->planned_share;
        $contributionKey =
            'budget:' . $budget->id .
            ':contribution:' . $allocation->id;
        $reviewKey =
            'budget:' . $budget->id .
            ':payment:' . $allocation->id .
            ':review';

        if (!$isAdmin && $isPaid) {
            ActivityNotifier::deleteByKey(
                $contributionKey,
                $userId
            );

            ActivityNotifier::notifyUser(
                recipientUserId: (int) $plan->admin_id,
                actorUserId: $userId,
                type: 'budget_payment_submitted',
                planId: (int) $plan->id,
                data: [
                    'activity_tab' => 'notifications',
                    'requires_action' => false,
                    'action' => 'view_budget',
                    'budget_id' => (int) $budget->id,
                    'allocation_id' => (int) $allocation->id,
                    'amount' => $amount,
                ],
                notificationKey: $reviewKey,
                replaceExisting: true,
            );
        }

        if (
            $isAdmin &&
            $allocation->user_id !== null &&
            (int) $allocation->user_id !== $userId
        ) {
            ActivityNotifier::deleteByKey($reviewKey);

            if ($isPaid) {
                ActivityNotifier::deleteByKey(
                    $contributionKey,
                    (int) $allocation->user_id
                );

                ActivityNotifier::notifyUser(
                    recipientUserId: (int) $allocation->user_id,
                    actorUserId: $userId,
                    type: 'budget_payment_verified',
                    planId: (int) $plan->id,
                    data: [
                        'activity_tab' => 'notifications',
                        'requires_action' => false,
                        'budget_id' => (int) $budget->id,
                        'allocation_id' => (int) $allocation->id,
                        'amount' => $amount,
                    ],
                    notificationKey:
                        'budget:' . $budget->id .
                        ':payment:' . $allocation->id .
                        ':result',
                    replaceExisting: true,
                );
            } elseif ($wasPaid) {
                ActivityNotifier::notifyUser(
                    recipientUserId: (int) $allocation->user_id,
                    actorUserId: $userId,
                    type: 'budget_payment_rejected',
                    planId: (int) $plan->id,
                    data: [
                        'activity_tab' => 'action_required',
                        'requires_action' => true,
                        'action' => 'settle_up',
                        'budget_id' => (int) $budget->id,
                        'allocation_id' => (int) $allocation->id,
                        'amount' => $amount,
                    ],
                    notificationKey: $contributionKey,
                    replaceExisting: true,
                );
            }
        }

        $this->notifyWhenAllContributionsSettled(
            $plan,
            $budget->fresh(),
            $userId
        );

        return response()->json([
            'success' => true,
            'message' => $isPaid
                ? 'Contribution marked as paid.'
                : 'Contribution marked as unpaid.',
            'budget' => $this->serializeBudget(
                $request,
                $plan,
                $budget
            ),
        ]);
    }

    public function destroy(
        Request $request,
        Plan $plan
    ): JsonResponse {
        $this->ensurePlanAdmin($request, $plan);

        $budget = $plan->budget;

        if ($budget === null) {
            return response()->json([
                'success' => false,
                'message' =>
                    'No budget plan exists for this plan.',
            ], 404);
        }

        $budgetId = (int) $budget->id;
        $budget->delete();

        ActivityNotifier::deleteByTypesForPlan(
            (int) $plan->id,
            [
                'budget_contribution_required',
                'budget_contribution_changed',
                'budget_allocation_assigned',
                'budget_allocation_changed',
                'budget_payment_submitted',
                'budget_payment_verified',
                'budget_payment_rejected',
                'budget_all_settled',
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Budget plan reset.',
        ]);
    }

    private function saveBudget(
        Request $request,
        Plan $plan,
        ?PlanBudget $budget
    ): PlanBudget {
        $validated = $request->validate([
            'split_type' => [
                'required',
                'in:equal,custom',
            ],

            'expenses' => [
                'required',
                'array',
                'min:1',
            ],

            'expenses.*.name' => [
                'required',
                'string',
                'max:150',
            ],

            'expenses.*.note' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'expenses.*.estimated_amount' => [
                'required',
                'numeric',
                'min:0.01',
                'max:9999999999.99',
            ],

            'allocations' => [
                'required',
                'array',
                'min:1',
            ],

            /*
             * Existing manual allocations send their database ID
             * so Paid/Unpaid can be preserved when nothing changed.
             */
            'allocations.*.id' => [
                'nullable',
                'integer',
            ],

            'allocations.*.allocation_id' => [
                'nullable',
                'integer',
            ],

            /*
             * A budget person may be either:
             * - a registered plan member with user_id
             * - a manually added person with manual_name
             */
            'allocations.*.user_id' => [
                'nullable',
                'integer',
            ],

            'allocations.*.manual_name' => [
                'nullable',
                'string',
                'max:150',
            ],

            'allocations.*.is_included' => [
                'required',
                'boolean',
            ],

            'allocations.*.planned_share' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999.99',
            ],
        ]);

        $validUserIds = $this
            ->planUsers($plan)
            ->pluck('id')
            ->map(
                fn ($id) => (int) $id
            )
            ->all();

        $seenUserIds = [];
        $seenManualNames = [];
        $seenAllocationIds = [];

        $allocationRows = collect(
            $validated['allocations']
        )
            ->values()
            ->map(
                function (
                    array $item,
                    int $index
                ) use (
                    $validUserIds,
                    &$seenUserIds,
                    &$seenManualNames,
                    &$seenAllocationIds
                ): array {
                    $userId = isset($item['user_id'])
                        ? (int) $item['user_id']
                        : null;

                    $manualName = isset($item['manual_name'])
                        ? trim((string) $item['manual_name'])
                        : '';

                    $allocationIdValue =
                        $item['id'] ??
                        $item['allocation_id'] ??
                        null;

                    $allocationId = $allocationIdValue === null
                        ? null
                        : (int) $allocationIdValue;

                    if (
                        $userId !== null &&
                        $manualName !== ''
                    ) {
                        throw ValidationException::withMessages([
                            "allocations.{$index}" =>
                                'Choose a plan member or enter another person, not both.',
                        ]);
                    }

                    if (
                        $userId === null &&
                        $manualName === ''
                    ) {
                        throw ValidationException::withMessages([
                            "allocations.{$index}" =>
                                'Select a plan member or enter a name.',
                        ]);
                    }

                    if ($userId !== null) {
                        if (
                            !in_array(
                                $userId,
                                $validUserIds,
                                true
                            )
                        ) {
                            throw ValidationException::withMessages([
                                "allocations.{$index}.user_id" =>
                                    'The selected user is not a member of this plan.',
                            ]);
                        }

                        if (
                            in_array(
                                $userId,
                                $seenUserIds,
                                true
                            )
                        ) {
                            throw ValidationException::withMessages([
                                "allocations.{$index}.user_id" =>
                                    'The same plan member was added more than once.',
                            ]);
                        }

                        $seenUserIds[] = $userId;
                        $manualName = '';
                        $allocationId = null;
                    } else {
                        $manualKey = strtolower($manualName);

                        if (
                            in_array(
                                $manualKey,
                                $seenManualNames,
                                true
                            )
                        ) {
                            throw ValidationException::withMessages([
                                "allocations.{$index}.manual_name" =>
                                    'The same manually added person appears more than once.',
                            ]);
                        }

                        $seenManualNames[] = $manualKey;

                        if ($allocationId !== null) {
                            if (
                                in_array(
                                    $allocationId,
                                    $seenAllocationIds,
                                    true
                                )
                            ) {
                                throw ValidationException::withMessages([
                                    "allocations.{$index}.id" =>
                                        'The same budget allocation appears more than once.',
                                ]);
                            }

                            $seenAllocationIds[] = $allocationId;
                        }
                    }

                    return [
                        'allocation_id' => $allocationId,
                        'user_id' => $userId,
                        'manual_name' => $manualName === ''
                            ? null
                            : $manualName,
                        'is_included' =>
                            (bool) $item['is_included'],
                        'planned_share_cents' =>
                            $this->toCents(
                                $item['planned_share'] ?? 0
                            ),
                    ];
                }
            )
            ->values();

        $expenseRows = collect(
            $validated['expenses']
        )
            ->values()
            ->map(
                function (
                    array $expense,
                    int $index
                ): array {
                    $note = isset($expense['note'])
                        ? trim(
                            (string) $expense['note']
                        )
                        : '';

                    return [
                        'name' => trim(
                            $expense['name']
                        ),
                        'note' => $note === ''
                            ? null
                            : $note,
                        'estimated_amount' =>
                            $this->fromCents(
                                $this->toCents(
                                    $expense[
                                        'estimated_amount'
                                    ]
                                )
                            ),
                        'position' => $index,
                    ];
                }
            );

        $totalEstimatedCents =
            $expenseRows->sum(
                fn (array $expense) =>
                    $this->toCents(
                        $expense[
                            'estimated_amount'
                        ]
                    )
            );

        $includedIndexes =
            $allocationRows
                ->keys()
                ->filter(
                    fn (int $index) =>
                        $allocationRows[
                            $index
                        ]['is_included']
                )
                ->values();

        if ($includedIndexes->isEmpty()) {
            throw ValidationException::withMessages([
                'allocations' =>
                    'Add at least one person to the budget.',
            ]);
        }

        if ($validated['split_type'] === 'equal') {
            $includedCount = $includedIndexes->count();

            $baseShare = intdiv(
                $totalEstimatedCents,
                $includedCount
            );

            $remainingCents =
                $totalEstimatedCents % $includedCount;

            $includedPosition = 0;

            $allocationRows = $allocationRows
                ->map(
                    function (array $row) use (
                        $baseShare,
                        $remainingCents,
                        &$includedPosition
                    ): array {
                        if (!$row['is_included']) {
                            $row['planned_share_cents'] = 0;

                            return $row;
                        }

                        $extraCent =
                            $includedPosition < $remainingCents
                                ? 1
                                : 0;

                        $row['planned_share_cents'] =
                            $baseShare + $extraCent;

                        $includedPosition++;

                        return $row;
                    }
                )
                ->values();
        } else {
            $allocationRows = $allocationRows
                ->map(
                    function (array $row): array {
                        if (!$row['is_included']) {
                            $row['planned_share_cents'] = 0;
                        }

                        return $row;
                    }
                )
                ->values();

            $allocatedCents = $allocationRows->sum(
                fn (array $row) =>
                    $row['is_included']
                        ? $row['planned_share_cents']
                        : 0
            );

            if ($allocatedCents > $totalEstimatedCents) {
                $excess = $this->fromCents(
                    $allocatedCents - $totalEstimatedCents
                );

                throw ValidationException::withMessages([
                    'allocations' =>
                        "Custom allocations cannot exceed the estimated budget. Excess: {$excess}",
                ]);
            }
        }

        return DB::transaction(
            function () use (
                $request,
                $plan,
                $budget,
                $validated,
                $expenseRows,
                $allocationRows,
                $totalEstimatedCents
            ): PlanBudget {
                if ($budget === null) {
                    $budget =
                        PlanBudget::create([
                            'plan_id' =>
                                $plan->id,

                            'created_by' =>
                                $request
                                    ->user()
                                    ->id,

                            'updated_by' =>
                                $request
                                    ->user()
                                    ->id,

                            'split_type' =>
                                $validated[
                                    'split_type'
                                ],

                            'total_estimated' =>
                                $this->fromCents(
                                    $totalEstimatedCents
                                ),

                            'published_at' =>
                                now(),

                            'needs_review' =>
                                false,
                        ]);
                } else {
                    $budget->update([
                        'updated_by' =>
                            $request
                                ->user()
                                ->id,

                        'split_type' =>
                            $validated[
                                'split_type'
                            ],

                        'total_estimated' =>
                            $this->fromCents(
                                $totalEstimatedCents
                            ),

                        'needs_review' =>
                            false,

                        'review_reason' =>
                            null,

                        'review_context' =>
                            null,

                        'reviewed_at' =>
                            now(),

                        'reviewed_by' =>
                            $request
                                ->user()
                                ->id,
                    ]);
                }

                $budget
                    ->expenses()
                    ->delete();

                $budget
                    ->expenses()
                    ->createMany(
                        $expenseRows->all()
                    );

                $keptAllocationIds = [];

                foreach (
                    $allocationRows
                    as $row
                ) {
                    if ($row['user_id'] !== null) {
                        /*
                         * Find by user_id even when the existing
                         * allocation was previously marked Former Member.
                         * Re-adding the same user safely reactivates it.
                         */
                        $existing = $budget
                            ->allocations()
                            ->where(
                                'user_id',
                                $row['user_id']
                            )
                            ->first();
                    } elseif (
                        $row['allocation_id'] !== null
                    ) {
                        $existing = $budget
                            ->allocations()
                            ->whereKey(
                                $row['allocation_id']
                            )
                            ->whereNull('user_id')
                            ->where(
                                'is_former_member',
                                false
                            )
                            ->first();

                        if ($existing === null) {
                            throw ValidationException::withMessages([
                                'allocations' =>
                                    'One of the manually added people is no longer available. Reload the budget and try again.',
                            ]);
                        }
                    } else {
                        $existing = null;
                    }

                    $newPlannedShare =
                        $this->fromCents(
                            $row[
                                'planned_share_cents'
                            ]
                        );

                    $manualNameChanged =
                        $existing !== null &&
                        trim(
                            (string) $existing
                                ->manual_name
                        ) !==
                        trim(
                            (string) (
                                $row[
                                    'manual_name'
                                ] ?? ''
                            )
                        );

                    $shareChanged =
                        $existing !== null &&
                        (
                            $this->toCents(
                                $existing
                                    ->planned_share
                            ) !==
                                $row[
                                    'planned_share_cents'
                                ] ||
                            (bool) $existing
                                ->is_included !==
                                (bool) $row[
                                    'is_included'
                                ] ||
                            (bool) $existing
                                ->is_former_member ||
                            $manualNameChanged
                        );

                    $payload = [
                        'user_id' =>
                            $row['user_id'],

                        'manual_name' =>
                            $row['manual_name'],

                        'is_included' =>
                            $row[
                                'is_included'
                            ],

                        'planned_share' =>
                            $newPlannedShare,

                        'is_former_member' =>
                            false,

                        'former_member_name' =>
                            null,

                        'member_left_at' =>
                            null,
                    ];

                    if (
                        $shareChanged ||
                        !$row['is_included']
                    ) {
                        $payload['is_paid'] =
                            false;

                        $payload['paid_at'] =
                            null;

                        $payload[
                            'marked_paid_by'
                        ] = null;
                    }

                    if ($existing === null) {
                        $savedAllocation = $budget
                            ->allocations()
                            ->create($payload);
                    } else {
                        $existing->update($payload);

                        $savedAllocation =
                            $existing->fresh();
                    }

                    $keptAllocationIds[] =
                        (int) $savedAllocation->id;
                }

                /*
                 * Anything removed from the current budget list
                 * is removed only from the budget. The user stays
                 * a member of the plan and can be added back later.
                 *
                 * Former-member history is never deleted here.
                 */
                $activeAllocations = $budget
                    ->allocations()
                    ->where(
                        'is_former_member',
                        false
                    );

                if (empty($keptAllocationIds)) {
                    $activeAllocations->delete();
                } else {
                    $activeAllocations
                        ->whereNotIn(
                            'id',
                            $keptAllocationIds
                        )
                        ->delete();
                }

                return $budget->fresh();
            }
        );
    }

    private function syncContributionNotifications(
        Plan $plan,
        PlanBudget $budget,
        int $actorUserId,
        array $oldAllocations
    ): void {
        $budget->load('allocations');

        $currentIds = [];

        foreach ($budget->allocations as $allocation) {
            $allocationId = (int) $allocation->id;
            $currentIds[] = $allocationId;

            if (
                $allocation->user_id === null ||
                !$allocation->is_included ||
                $allocation->is_former_member ||
                $allocation->is_paid
            ) {
                ActivityNotifier::deleteByKey(
                    'budget:' . $budget->id .
                    ':contribution:' . $allocationId
                );
                continue;
            }

            $old = $oldAllocations[$allocationId] ?? null;
            $amountChanged = $old !== null &&
                round((float) $old['planned_share'], 2) !==
                round((float) $allocation->planned_share, 2);

            $trackingEnabled =
                (bool) $budget->contribution_tracking_enabled;

            ActivityNotifier::notifyUser(
                recipientUserId: (int) $allocation->user_id,
                actorUserId: $actorUserId,
                type: $trackingEnabled
                    ? ($amountChanged
                        ? 'budget_contribution_changed'
                        : 'budget_contribution_required')
                    : ($amountChanged
                        ? 'budget_allocation_changed'
                        : 'budget_allocation_assigned'),
                planId: (int) $plan->id,
                data: [
                    'activity_tab' => $trackingEnabled
                        ? 'action_required'
                        : 'notifications',
                    'requires_action' => $trackingEnabled,
                    'action' => $trackingEnabled
                        ? 'settle_up'
                        : 'view_budget',
                    'budget_id' => (int) $budget->id,
                    'allocation_id' => $allocationId,
                    'amount' => (float) $allocation->planned_share,
                ],
                notificationKey:
                    'budget:' . $budget->id .
                    ':contribution:' . $allocationId,
                replaceExisting: true,
            );
        }

        foreach ($oldAllocations as $oldAllocationId => $old) {
            if (!in_array((int) $oldAllocationId, $currentIds, true)) {
                ActivityNotifier::deleteByKey(
                    'budget:' . $budget->id .
                    ':contribution:' . $oldAllocationId
                );
                ActivityNotifier::deleteByKey(
                    'budget:' . $budget->id .
                    ':payment:' . $oldAllocationId .
                    ':review'
                );
            }
        }
    }

    private function notifyWhenAllContributionsSettled(
        Plan $plan,
        PlanBudget $budget,
        int $actorUserId
    ): void {
        $requiredCount = $budget->allocations()
            ->where('is_included', true)
            ->where('is_former_member', false)
            ->whereNotNull('user_id')
            ->count();

        if ($requiredCount === 0) {
            return;
        }

        $unpaidCount = $budget->allocations()
            ->where('is_included', true)
            ->where('is_former_member', false)
            ->whereNotNull('user_id')
            ->where('is_paid', false)
            ->count();

        if ($unpaidCount > 0) {
            ActivityNotifier::deleteByKey(
                'budget:' . $budget->id . ':all-settled'
            );
            return;
        }

        ActivityNotifier::notifyUser(
            recipientUserId: (int) $plan->admin_id,
            actorUserId: $actorUserId,
            type: 'budget_all_settled',
            planId: (int) $plan->id,
            data: [
                'activity_tab' => 'notifications',
                'requires_action' => false,
                'budget_id' => (int) $budget->id,
            ],
            notificationKey:
                'budget:' . $budget->id . ':all-settled',
            replaceExisting: true,
        );
    }

    private function serializeBudget(
        Request $request,
        Plan $plan,
        PlanBudget $budget
    ): array {
        $budget->load([
            'creator',
            'updater',
            'reviewer',
            'expenses',
            'allocations.user',
            'allocations.markedPaidBy',
        ]);

        $currentUserId =
            (int) $request->user()->id;

        $isAdmin = $this->isPlanAdmin(
            $request,
            $plan
        );

        $canSeeAllStatuses =
            $isAdmin ||
            $budget->show_status_to_members;

        $estimatedCents =
            $this->toCents(
                $budget->total_estimated
            );

        $allocatedCents = 0;
        $activeCollectedCents = 0;
        $formerCollectedCents = 0;

        $allocations = $budget
            ->allocations
            ->sortBy(
                fn (
                    BudgetAllocation $allocation
                ) => sprintf(
                    '%d-%s',
                    $allocation->is_former_member
                        ? 1
                        : 0,
                    strtolower(
                        (string) (
                            $allocation
                                ->former_member_name ??
                            $allocation
                                ->manual_name ??
                            $allocation
                                ->user
                                ?->name ??
                            ''
                        )
                    )
                )
            )
            ->values()
            ->map(
                function (
                    BudgetAllocation $allocation
                ) use (
                    $budget,
                    $currentUserId,
                    $isAdmin,
                    $canSeeAllStatuses,
                    $plan,
                    &$allocatedCents,
                    &$activeCollectedCents,
                    &$formerCollectedCents
                ): array {
                    $plannedShareCents =
                        $this->toCents(
                            $allocation
                                ->planned_share
                        );

                    if (
                        !$allocation
                            ->is_former_member &&
                        $allocation
                            ->is_included
                    ) {
                        $allocatedCents +=
                            $plannedShareCents;

                        if (
                            $allocation
                                ->is_paid
                        ) {
                            $activeCollectedCents +=
                                $plannedShareCents;
                        }
                    } elseif (
                        $allocation
                            ->is_former_member &&
                        $allocation
                            ->is_paid
                    ) {
                        $formerCollectedCents +=
                            $plannedShareCents;
                    }

                    $isOwnAllocation =
                        (int) $allocation
                            ->user_id ===
                        $currentUserId;

                    /*
                     * When contribution tracking is off,
                     * status information is hidden but
                     * remains stored in the database.
                     */
                    $canSeeStatus =
                        (bool) $budget
                            ->contribution_tracking_enabled &&
                        (
                            $canSeeAllStatuses ||
                            $isOwnAllocation
                        );

                    return [
                        'id' =>
                            $allocation->id,

                        'user_id' =>
                            $allocation
                                ->user_id,

                        'name' =>
                            $allocation
                                ->former_member_name ??
                            $allocation
                                ->manual_name ??
                            $allocation
                                ->user
                                ?->name,

                        'manual_name' =>
                            $allocation
                                ->manual_name,

                        'is_manual' =>
                            !$allocation
                                ->is_former_member &&
                            $allocation
                                ->user_id === null,

                        'username' =>
                            $allocation
                                ->user
                                ?->username,

                        'profile_photo_url' =>
                            $allocation
                                ->user
                                ?->profile_photo_url,

                        'is_plan_admin' =>
                            !$allocation
                                ->is_former_member &&
                            $allocation
                                ->user_id !== null &&
                            (int) $allocation
                                ->user_id ===
                            (int) $plan
                                ->admin_id,

                        'is_former_member' =>
                            (bool) $allocation
                                ->is_former_member,

                        'member_left_at' =>
                            optional(
                                $allocation
                                    ->member_left_at
                            )->toISOString(),

                        'is_included' =>
                            (bool) $allocation
                                ->is_included,

                        'planned_share' =>
                            (float) $allocation
                                ->planned_share,

                        'is_paid' =>
                            $canSeeStatus
                                ? (bool) $allocation
                                    ->is_paid
                                : null,

                        'paid_at' =>
                            $canSeeStatus
                                ? optional(
                                    $allocation
                                        ->paid_at
                                )->toISOString()
                                : null,

                        'marked_paid_by' =>
                            $canSeeStatus &&
                            $allocation
                                ->markedPaidBy
                                ? [
                                    'id' =>
                                        $allocation
                                            ->markedPaidBy
                                            ->id,
                                    'name' =>
                                        $allocation
                                            ->markedPaidBy
                                            ->name,
                                ]
                                : null,

                        'can_mark_paid' =>
                            (bool) (
                                $budget
                                    ->contribution_tracking_enabled &&
                                !$allocation
                                    ->is_former_member &&
                                $allocation
                                    ->is_included &&
                                (
                                    $isAdmin ||
                                    (
                                        $isOwnAllocation &&
                                        $budget
                                            ->allow_member_mark_paid
                                    )
                                )
                            ),
                    ];
                }
            );

        $collectedCents =
            $activeCollectedCents +
            $formerCollectedCents;

        return [
            'id' => $budget->id,
            'plan_id' => $budget->plan_id,

            'split_type' =>
                $budget->split_type,

            'needs_review' =>
                (bool) $budget->needs_review,

            'review_reason' =>
                $budget->review_reason,

            'review_context' =>
                $budget->review_context,

            'reviewed_at' =>
                optional(
                    $budget->reviewed_at
                )->toISOString(),

            'reviewed_by' =>
                $budget->reviewer
                    ? [
                        'id' =>
                            $budget->reviewer->id,
                        'name' =>
                            $budget->reviewer->name,
                    ]
                    : null,

            'is_published' =>
                $budget->published_at !== null,

            'published_at' =>
                optional(
                    $budget->published_at
                )->toISOString(),

            'contribution_tracking_enabled' =>
                (bool) $budget
                    ->contribution_tracking_enabled,

            'allow_member_mark_paid' =>
                (bool) $budget
                    ->allow_member_mark_paid,

            'show_status_to_members' =>
                (bool) $budget
                    ->show_status_to_members,

            'summary' => [
                'estimated_budget' =>
                    (float) $budget
                        ->total_estimated,

                'allocated_amount' =>
                    (float) $this->fromCents(
                        $allocatedCents
                    ),

                'unallocated_amount' =>
                    (float) $this->fromCents(
                        max(
                            $estimatedCents -
                            $allocatedCents,
                            0
                        )
                    ),

                'collected_amount' =>
                    (float) $this->fromCents(
                        $collectedCents
                    ),

                'active_collected_amount' =>
                    (float) $this->fromCents(
                        $activeCollectedCents
                    ),

                'former_collected_amount' =>
                    (float) $this->fromCents(
                        $formerCollectedCents
                    ),

                'not_collected_amount' =>
                    (float) $this->fromCents(
                        max(
                            $estimatedCents -
                            $collectedCents,
                            0
                        )
                    ),
            ],

            'expenses' =>
                $budget
                    ->expenses
                    ->map(
                        fn ($expense) => [
                            'id' =>
                                $expense->id,

                            'name' =>
                                $expense->name,

                            'note' =>
                                $expense->note,

                            'estimated_amount' =>
                                (float) $expense
                                    ->estimated_amount,

                            'position' =>
                                (int) $expense
                                    ->position,
                        ]
                    )
                    ->values(),

            'allocations' => $allocations,

            'created_by' =>
                $budget->creator
                    ? [
                        'id' =>
                            $budget
                                ->creator
                                ->id,
                        'name' =>
                            $budget
                                ->creator
                                ->name,
                    ]
                    : null,

            'updated_by' =>
                $budget->updater
                    ? [
                        'id' =>
                            $budget
                                ->updater
                                ->id,
                        'name' =>
                            $budget
                                ->updater
                                ->name,
                    ]
                    : null,

            'created_at' =>
                optional(
                    $budget->created_at
                )->toISOString(),

            'updated_at' =>
                optional(
                    $budget->updated_at
                )->toISOString(),

            'can_manage_budget' =>
                $isAdmin,
        ];
    }

    private function serializePlanMembers(
        Plan $plan
    ): array {
        return $this
            ->planUsers($plan)
            ->sortBy(
                fn ($user) =>
                    strtolower(
                        (string) $user->name
                    )
            )
            ->values()
            ->map(
                fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,

                    'username' =>
                        $user->username,

                    'profile_photo_url' =>
                        $user
                            ->profile_photo_url,

                    'is_plan_admin' =>
                        (int) $user->id ===
                        (int) $plan->admin_id,
                ]
            )
            ->all();
    }

    private function planUsers(
        Plan $plan
    ): Collection {
        $plan->loadMissing([
            'admin',
            'members',
        ]);

        return collect([
            $plan->admin,
        ])
            ->filter()
            ->merge($plan->members)
            ->unique(
                fn ($user) =>
                    (int) $user->id
            )
            ->values();
    }

    private function ensureCanViewPlan(
        Request $request,
        Plan $plan
    ): void {
        if (
            $this->isPlanAdmin(
                $request,
                $plan
            )
        ) {
            return;
        }

        $isMember = $plan
            ->members()
            ->where(
                'users.id',
                $request
                    ->user()
                    ->id
            )
            ->exists();

        abort_unless(
            $isMember,
            403,
            'You are not a member of this plan.'
        );
    }

    private function ensurePlanAdmin(
        Request $request,
        Plan $plan
    ): void {
        abort_unless(
            $this->isPlanAdmin(
                $request,
                $plan
            ),
            403,
            'Only the Plan Admin can manage the budget.'
        );
    }

    private function isPlanAdmin(
        Request $request,
        Plan $plan
    ): bool {
        return (int) $plan->admin_id ===
            (int) $request->user()->id;
    }

    private function toCents(
        mixed $value
    ): int {
        return (int) round(
            ((float) $value) * 100
        );
    }

    private function fromCents(
        int $cents
    ): string {
        return number_format(
            $cents / 100,
            2,
            '.',
            ''
        );
    }
}