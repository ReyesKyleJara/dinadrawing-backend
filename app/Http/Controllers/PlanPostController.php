<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanPost;
use Illuminate\Http\Request;

class PlanPostController extends Controller
{
    public function index(Request $request, Plan $plan)
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
                'message' => 'You are not allowed to view posts in this plan.',
            ], 403);
        }

        $posts = PlanPost::where('plan_id', $plan->id)
            ->with('user:id,name,username,email')
            ->latest()
            ->get();

        return response()->json([
            'posts' => $posts,
        ]);
    }

    public function store(Request $request, Plan $plan)
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
                'message' => 'You are not allowed to post in this plan.',
            ], 403);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $post = PlanPost::create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'content' => $validated['content'],
        ]);

        $post->load('user:id,name,username,email');

        return response()->json([
            'message' => 'Post created successfully.',
            'post' => $post,
        ], 201);
    }
}