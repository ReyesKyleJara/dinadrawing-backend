<?php

namespace App\Http\Controllers;

use App\Models\BudgetAllocation;
use App\Models\Plan;
use App\Models\PlanBudget;
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

        $budget = $this->saveBudget(
            request: $request,
            plan: $plan,
            budget: $budget,
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

        if (!$allocation->is_included) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Excluded members do not have a contribution status.',
            ], 422);
        }

        $userId = (int) $request->user()->id;

        $isAdmin = $this->isPlanAdmin(
            $request,
            $plan
        );

        $isOwnAllocation =
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

        $allocation->update([
            'is_paid' => $isPaid,
            'paid_at' => $isPaid
                ? now()
                : null,
            'marked_paid_by' => $isPaid
                ? $userId
                : null,
        ]);

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

        $budget->delete();

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

            'allocations.*.user_id' => [
                'required',
                'integer',
                'distinct',
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

        $planUsers = $this->planUsers($plan);

        if ($planUsers->isEmpty()) {
            throw ValidationException::withMessages([
                'allocations' =>
                    'This plan has no members available for budget allocation.',
            ]);
        }

        $validUserIds = $planUsers
            ->pluck('id')
            ->map(
                fn ($id) => (int) $id
            )
            ->all();

        $requestedAllocations = collect(
            $validated['allocations']
        )->keyBy(
            fn (array $item) =>
                (int) $item['user_id']
        );

        foreach (
            $requestedAllocations->keys()
            as $requestedUserId
        ) {
            if (
                !in_array(
                    (int) $requestedUserId,
                    $validUserIds,
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'allocations' =>
                        'One or more selected users are not members of this plan.',
                ]);
            }
        }

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

        /*
         * Every actual plan member is represented.
         *
         * Members missing from the request are treated
         * as excluded instead of disappearing.
         */
        $allocationRows = $planUsers
            ->values()
            ->map(
                function ($user) use (
                    $requestedAllocations
                ): array {
                    $requested =
                        $requestedAllocations->get(
                            (int) $user->id
                        );

                    return [
                        'user_id' =>
                            (int) $user->id,

                        'is_included' =>
                            (bool) (
                                $requested[
                                    'is_included'
                                ] ?? false
                            ),

                        'planned_share_cents' =>
                            $this->toCents(
                                $requested[
                                    'planned_share'
                                ] ?? 0
                            ),
                    ];
                }
            )
            ->values();

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
                    'Include at least one plan member in the budget.',
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
        ->map(function (array $row) use (
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
        })
        ->values();
} else {
    $allocationRows = $allocationRows
        ->map(function (array $row): array {
            if (!$row['is_included']) {
                $row['planned_share_cents'] = 0;
            }

            return $row;
        })
        ->values();

    $allocatedCents = $allocationRows->sum(
        fn (array $row) =>
            $row['is_included']
                ? $row['planned_share_cents']
                : 0
    );

    if ($allocatedCents !== $totalEstimatedCents) {
        $difference = $this->fromCents(
            $totalEstimatedCents - $allocatedCents
        );

        throw ValidationException::withMessages([
            'allocations' =>
                "Custom allocations must match the estimated budget. Difference: {$difference}",
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
                $totalEstimatedCents,
                $validUserIds
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
                    ]);
                }

                /*
                 * Replace the expense list with the
                 * newly submitted ordered list.
                 */
                $budget
                    ->expenses()
                    ->delete();

                $budget
                    ->expenses()
                    ->createMany(
                        $expenseRows->all()
                    );

                foreach (
                    $allocationRows
                    as $row
                ) {
                    $existing =
                        $budget
                            ->allocations()
                            ->where(
                                'user_id',
                                $row['user_id']
                            )
                            ->first();

                    $newPlannedShare =
                        $this->fromCents(
                            $row[
                                'planned_share_cents'
                            ]
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
                                ]
                        );

                    $payload = [
                        'is_included' =>
                            $row[
                                'is_included'
                            ],

                        'planned_share' =>
                            $newPlannedShare,
                    ];

                    /*
                     * If a member's amount or inclusion
                     * changes, reset Paid back to Unpaid.
                     */
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

                    $budget
                        ->allocations()
                        ->updateOrCreate(
                            [
                                'user_id' =>
                                    $row[
                                        'user_id'
                                    ],
                            ],
                            $payload
                        );
                }

                /*
                 * Remove allocations for users who are
                 * no longer members of the plan.
                 */
                $budget
                    ->allocations()
                    ->whereNotIn(
                        'user_id',
                        $validUserIds
                    )
                    ->delete();

                return $budget->fresh();
            }
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
        $collectedCents = 0;

        $allocations = $budget
            ->allocations
            ->sortBy(
                fn (
                    BudgetAllocation $allocation
                ) => strtolower(
                    (string) (
                        $allocation
                            ->user
                            ?->name ?? ''
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
                    &$collectedCents
                ): array {
                    $plannedShareCents =
                        $this->toCents(
                            $allocation
                                ->planned_share
                        );

                    if (
                        $allocation
                            ->is_included
                    ) {
                        $allocatedCents +=
                            $plannedShareCents;

                        if (
                            $allocation
                                ->is_paid
                        ) {
                            $collectedCents +=
                                $plannedShareCents;
                        }
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
                                ->user
                                ?->name,

                        'username' =>
                            $allocation
                                ->user
                                ?->username,

                        'profile_photo_url' =>
                            $allocation
                                ->user
                                ?->profile_photo_url,

                        'is_plan_admin' =>
                            (int) $allocation
                                ->user_id ===
                            (int) $plan
                                ->admin_id,

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

        return [
            'id' => $budget->id,
            'plan_id' => $budget->plan_id,

            'split_type' =>
                $budget->split_type,

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
                        $estimatedCents -
                        $allocatedCents
                    ),

                'collected_amount' =>
                    (float) $this->fromCents(
                        $collectedCents
                    ),

                'not_collected_amount' =>
                    (float) $this->fromCents(
                        max(
                            $allocatedCents -
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