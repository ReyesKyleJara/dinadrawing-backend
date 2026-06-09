<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $plansByMe = Plan::where('creator_id', $user->id)
            ->where('is_deleted', false)
            ->latest()
            ->get();

        $plansWithMe = Plan::whereHas('members', function ($query) use ($user) {
                $query->where('users.id', $user->id)
                    ->where('plan_members.role', 'member');
            })
            ->where('is_deleted', false)
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
            'creator_id' => $request->user()->id,
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
        ]);

        $plan->members()->attach($request->user()->id, [
            'role' => 'creator',
        ]);

        return response()->json([
            'message' => 'Plan created successfully',
            'plan' => $plan,
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

        $plan->load('members');

        return response()->json([
            'message' => 'Plan retrieved successfully',
            'plan' => $plan,
        ]);
    }

    public function join(Request $request)
    {
        $validated = $request->validate([
            'invite_code' => ['required', 'string'],
        ]);

        $plan = Plan::where('invite_code', strtoupper($validated['invite_code']))
            ->where('is_deleted', false)
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
                'plan' => $plan,
            ]);
        }

        $plan->members()->attach($request->user()->id, [
            'role' => 'member',
        ]);

        return response()->json([
            'message' => 'Joined plan successfully',
            'plan' => $plan,
        ]);
    }

    public function updateBanner(Request $request, Plan $plan)
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
                'message' => 'You are not allowed to update this plan.',
            ], 403);
        }

        $validated = $request->validate([
            'banner_color' => ['required', 'string', 'max:20'],
        ]);

        $plan->update([
            'banner_color' => $validated['banner_color'],
        ]);

        $plan->load('members');

        return response()->json([
            'message' => 'Banner updated successfully.',
            'plan' => $plan,
        ]);
    }

    private function generateUniqueInviteCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Plan::where('invite_code', $code)->exists());

        return $code;
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