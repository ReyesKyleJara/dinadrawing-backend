<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $plansByMe = Plan::with(['admin', 'members'])
            ->where('admin_id', $user->id)
            ->where('is_deleted', false)
            ->where('is_archived', false)
            ->latest()
            ->get();

        $plansWithMe = Plan::with(['admin', 'members'])
            ->whereHas('members', function ($query) use ($user) {
                $query->where('users.id', $user->id)
                    ->where('plan_members.role', 'member');
            })
            ->where('admin_id', '!=', $user->id)
            ->where('is_deleted', false)
            ->where('is_archived', false)
            ->latest()
            ->get();

        return response()->json([
            'plans_by_me' => $plansByMe,
            'plans_with_me' => $plansWithMe,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'plan_date' => ['nullable', 'date'],
            'plan_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'status' => ['nullable', 'string', 'max:255'],
        ]);

        $inviteCode = $this->generateUniqueInviteCode();
        $bannerColor = $this->generateRandomBannerColor();

        $plan = Plan::create([
            'admin_id' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'plan_date' => $validated['plan_date'] ?? null,
            'plan_time' => $validated['plan_time'] ?? null,
            'location' => $validated['location'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'invite_code' => $inviteCode,
            'status' => $validated['status'] ?? 'Plan Ongoing',
            'banner_color' => $bannerColor,
            'theme_color' => '#F2B73F',
            'is_archived' => false,
            'is_deleted' => false,
        ]);

        $plan->members()->attach($request->user()->id, [
            'role' => 'admin',
        ]);

        return response()->json([
            'message' => 'Plan created successfully.',
            'plan' => $plan->load(['admin', 'members']),
        ], 201);
    }

    public function show(Request $request, Plan $plan)
    {
        $user = $request->user();

        if ($plan->is_deleted) {
            return response()->json([
                'message' => 'Plan not found.',
            ], 404);
        }

        $isMember = $plan->members()
            ->where('users.id', $user->id)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'message' => 'You are not allowed to view this plan.',
            ], 403);
        }

        return response()->json([
            'message' => 'Plan retrieved successfully.',
            'plan' => $plan->load(['admin', 'members']),
        ]);
    }

    public function join(Request $request)
    {
        $validated = $request->validate([
            'invite_code' => ['required', 'string'],
        ]);

        $inviteCode = strtoupper(trim($validated['invite_code']));

        $plan = Plan::where('invite_code', $inviteCode)
            ->where('is_deleted', false)
            ->where('is_archived', false)
            ->first();

        if (!$plan) {
            return response()->json([
                'message' => 'Invalid plan code.',
            ], 404);
        }

        $alreadyMember = $plan->members()
            ->where('users.id', $request->user()->id)
            ->exists();

        if ($alreadyMember) {
            return response()->json([
                'message' => 'You are already a member of this plan.',
                'plan' => $plan->load(['admin', 'members']),
            ]);
        }

        $plan->members()->attach($request->user()->id, [
            'role' => 'member',
        ]);

        return response()->json([
            'message' => 'Joined plan successfully.',
            'plan' => $plan->load(['admin', 'members']),
        ]);
    }

    public function leave(Request $request, Plan $plan)
    {
        $user = $request->user();

        if ($plan->is_deleted) {
            return response()->json([
                'message' => 'Plan not found.',
            ], 404);
        }

        $isMember = $plan->members()
            ->where('users.id', $user->id)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'message' => 'You are not a member of this plan.',
            ], 403);
        }

        if ($plan->admin_id !== $user->id) {
            DB::transaction(function () use ($plan, $user) {
                $this->markBudgetForMemberDeparture(
                    $plan,
                    $user
                );

                $plan->members()->detach($user->id);
            });

            return response()->json([
                'message' => 'You have left the plan successfully.',
                'budget_needs_review' =>
                    (bool) optional($plan->budget)
                        ->needs_review,
            ]);
        }

        $newAdminId = $request->input('new_admin_id');

        if (!$newAdminId) {
            return response()->json([
                'message' => 'Please transfer admin role to another member before leaving.',
            ], 422);
        }

        if ((int) $newAdminId === (int) $user->id) {
            return response()->json([
                'message' => 'You cannot transfer admin role to yourself.',
            ], 422);
        }

        $newAdminIsMember = $plan->members()
            ->where('users.id', $newAdminId)
            ->where('plan_members.role', 'member')
            ->exists();

        if (!$newAdminIsMember) {
            return response()->json([
                'message' => 'Selected user must be a member of this plan.',
            ], 422);
        }

        DB::transaction(function () use ($plan, $user, $newAdminId) {
            $this->markBudgetForMemberDeparture(
                $plan,
                $user
            );

            $plan->update([
                'admin_id' => $newAdminId,
            ]);

            $plan->members()->updateExistingPivot($newAdminId, [
                'role' => 'admin',
            ]);

            $plan->members()->detach($user->id);
        });

        return response()->json([
            'message' => 'Admin role transferred and you have left the plan successfully.',
        ]);
    }

    public function updateBanner(Request $request, Plan $plan)
    {
        return $this->updateAppearance($request, $plan);
    }

    public function updateAppearance(Request $request, Plan $plan)
    {
        if ($plan->is_deleted) {
            return response()->json([
                'message' => 'Plan not found.',
            ], 404);
        }

        if ((int) $plan->admin_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Only the plan admin can update this plan.',
            ], 403);
        }

        $validated = $request->validate([
            'banner_color' => [
                'nullable',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/',
            ],
            'theme_color' => [
                'nullable',
                'string',
                Rule::in($this->allowedThemeColors()),
            ],
            'banner_image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
            'remove_banner_image' => [
                'nullable',
                'boolean',
            ],
        ]);

        $updates = [];

        if (array_key_exists('banner_color', $validated)) {
            $updates['banner_color'] = strtoupper(
                $validated['banner_color']
            );
        }

        if (array_key_exists('theme_color', $validated)) {
            $updates['theme_color'] = strtoupper(
                $validated['theme_color']
            );
        }

        $removeImage = (bool) ($validated['remove_banner_image'] ?? false);

        if ($removeImage && $plan->banner_image_path) {
            Storage::disk('public')->delete(
                $plan->banner_image_path
            );

            $updates['banner_image_path'] = null;
        }

        if ($request->hasFile('banner_image')) {
            if ($plan->banner_image_path) {
                Storage::disk('public')->delete(
                    $plan->banner_image_path
                );
            }

            $updates['banner_image_path'] = $request
                ->file('banner_image')
                ->store('plan-banners', 'public');
        }

        if (empty($updates)) {
            return response()->json([
                'message' => 'There are no appearance changes to save.',
            ], 422);
        }

        $plan->update($updates);

        return response()->json([
            'message' => 'Plan appearance updated successfully.',
            'plan' => $plan->fresh()->load([
                'admin',
                'members',
            ]),
        ]);
    }

    public function update(Request $request, Plan $plan)
    {
        if ($plan->is_deleted) {
            return response()->json([
                'message' => 'Plan not found.',
            ], 404);
        }

        if ($plan->admin_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Only the plan admin can edit this plan.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'plan_date' => ['nullable', 'string'],
            'plan_time' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'status' => ['nullable', 'string', 'max:255'],
            'banner_color' => [
                'nullable',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/',
            ],
            'theme_color' => [
                'nullable',
                'string',
                Rule::in($this->allowedThemeColors()),
            ],
        ]);

        $plan->update($validated);

        return response()->json([
            'message' => 'Plan updated successfully.',
            'plan' => $plan->load(['admin', 'members']),
        ]);
    }

    public function destroy(Request $request, Plan $plan)
    {
        if ($plan->admin_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Only the plan admin can delete this plan.',
            ], 403);
        }

        $plan->update([
            'is_deleted' => true,
            'is_archived' => false,
        ]);

        return response()->json([
            'message' => 'Plan moved to Deleted Plans.',
        ]);
    }

    public function archivedPlans(Request $request)
    {
        $user = $request->user();

        $plansByMe = Plan::with(['admin', 'members'])
            ->where('admin_id', $user->id)
            ->where('is_archived', true)
            ->where('is_deleted', false)
            ->latest()
            ->get();

        $plansWithMe = Plan::with(['admin', 'members'])
            ->whereHas('members', function ($query) use ($user) {
                $query->where('users.id', $user->id)
                    ->where('plan_members.role', 'member');
            })
            ->where('admin_id', '!=', $user->id)
            ->where('is_archived', true)
            ->where('is_deleted', false)
            ->latest()
            ->get();

        return response()->json([
            'plansByMe' => $plansByMe,
            'plansWithMe' => $plansWithMe,
            'plans_by_me' => $plansByMe,
            'plans_with_me' => $plansWithMe,
        ]);
    }

    public function archive(Request $request, Plan $plan)
    {
        if ($plan->is_deleted) {
            return response()->json([
                'message' => 'Plan not found.',
            ], 404);
        }

        if ($plan->admin_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Only the plan admin can archive this plan.',
            ], 403);
        }

        $plan->update([
            'is_archived' => true,
        ]);

        return response()->json([
            'message' => 'Plan archived successfully.',
            'plan' => $plan->load(['admin', 'members']),
        ]);
    }

    public function unarchive(Request $request, Plan $plan)
    {
        if ($plan->is_deleted) {
            return response()->json([
                'message' => 'Plan not found.',
            ], 404);
        }

        if ($plan->admin_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Only the plan admin can restore this plan.',
            ], 403);
        }

        $plan->update([
            'is_archived' => false,
        ]);

        return response()->json([
            'message' => 'Plan restored from Archived Plans.',
            'plan' => $plan->load(['admin', 'members']),
        ]);
    }

    public function deletedPlans(Request $request)
    {
        $user = $request->user();

        $plansByMe = Plan::with(['admin', 'members'])
            ->where('admin_id', $user->id)
            ->where('is_deleted', true)
            ->latest()
            ->get();

        $plansWithMe = Plan::with(['admin', 'members'])
            ->whereHas('members', function ($query) use ($user) {
                $query->where('users.id', $user->id)
                    ->where('plan_members.role', 'member');
            })
            ->where('admin_id', '!=', $user->id)
            ->where('is_deleted', true)
            ->latest()
            ->get();

        return response()->json([
            'plansByMe' => $plansByMe,
            'plansWithMe' => $plansWithMe,
            'plans_by_me' => $plansByMe,
            'plans_with_me' => $plansWithMe,
        ]);
    }

    public function restore(Request $request, Plan $plan)
    {
        if ($plan->admin_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Only the plan admin can restore this plan.',
            ], 403);
        }

        $plan->update([
            'is_deleted' => false,
            'is_archived' => false,
        ]);

        return response()->json([
            'message' => 'Plan restored successfully.',
            'plan' => $plan->load(['admin', 'members']),
        ]);
    }

    public function forceDelete(Request $request, Plan $plan)
    {
        if ($plan->admin_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Only the plan admin can permanently delete this plan.',
            ], 403);
        }

        $plan->delete();

        return response()->json([
            'message' => 'Plan permanently deleted.',
        ]);
    }

    private function markBudgetForMemberDeparture(
        Plan $plan,
        $user
    ): void {
        $budget = $plan->budget;

        if ($budget === null) {
            return;
        }

        $allocation = $budget
            ->allocations()
            ->where('user_id', $user->id)
            ->first();

        if ($allocation === null) {
            return;
        }

        $departures = collect(
            $budget->review_context['departures'] ?? []
        );

        $departures = $departures
            ->reject(
                fn (array $departure) =>
                    (int) ($departure['user_id'] ?? 0) ===
                    (int) $user->id
            )
            ->values();

        $departures->push([
            'user_id' => (int) $user->id,
            'name' => (string) $user->name,
            'planned_share' =>
                (float) $allocation->planned_share,
            'was_included' =>
                (bool) $allocation->is_included,
            'was_paid' =>
                (bool) $allocation->is_paid,
            'paid_at' => optional(
                $allocation->paid_at
            )->toISOString(),
            'left_at' => now()->toISOString(),
        ]);

        $allocation->update([
            'is_included' => false,
            'is_former_member' => true,
            'former_member_name' =>
                (string) $user->name,
            'member_left_at' => now(),
        ]);

        $budget->update([
            'needs_review' => true,
            'review_reason' => 'member_left',
            'review_context' => [
                'departures' => $departures->all(),
            ],
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);
    }

    private function generateUniqueInviteCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Plan::where('invite_code', $code)->exists());

        return $code;
    }

    private function allowedThemeColors(): array
    {
        return [
            '#F2B73F',
            '#4A78D6',
            '#8B5CF6',
            '#0F9D8A',
            '#E85D9E',
            '#F47B3A',
        ];
    }

    private function generateRandomBannerColor(): string
    {
        $colors = [
            '#FF8243',
            '#FFC0CB',
            '#FCE883',
            '#069494',
            '#FF4F79',
            '#00C2A8',
            '#FFD166',
            '#2F80ED',
            '#F7F7FF',
        ];

        return $colors[array_rand($colors)];
    }
}