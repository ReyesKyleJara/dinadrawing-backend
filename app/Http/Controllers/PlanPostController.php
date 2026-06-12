<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanPost;
use App\Models\PlanPostVote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
            ->with([
                'user:id,name,username,email',
                'votes.user:id,name,username',
            ])
            ->latest()
            ->get()
            ->map(function ($post) use ($request) {
                return $this->attachPollVoteData(
                    $this->attachImageUrl($post, $request),
                    $request
                );
            });

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

        $postType = $request->input('post_type', 'text');

        if ($postType === 'poll') {
            return $this->storePoll($request, $plan, $user->id);
        }

        return $this->storeTextOrImagePost($request, $plan, $user->id);
    }

    public function vote(Request $request, PlanPost $post)
    {
        $user = $request->user();

        if ($post->post_type !== 'poll') {
            return response()->json([
                'message' => 'This post is not a poll.',
            ], 422);
        }

        $plan = $post->plan;

        if (!$plan || $plan->is_deleted) {
            return response()->json([
                'message' => 'Plan not found.',
            ], 404);
        }

        $isMember = $plan->members()
            ->where('users.id', $user->id)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'message' => 'You are not allowed to vote in this poll.',
            ], 403);
        }

        $validated = $request->validate([
            'option_indexes' => ['required', 'array', 'min:1'],
            'option_indexes.*' => ['required', 'integer', 'min:0'],
        ]);

        $optionIndexes = collect($validated['option_indexes'])
            ->map(fn ($index) => (int) $index)
            ->unique()
            ->values()
            ->toArray();

        $options = $post->poll_options ?? [];

        foreach ($optionIndexes as $index) {
            if (!array_key_exists($index, $options)) {
                return response()->json([
                    'message' => 'Invalid poll option selected.',
                ], 422);
            }
        }

        if (!$post->allow_multiple && count($optionIndexes) > 1) {
            return response()->json([
                'message' => 'This poll only allows one vote.',
            ], 422);
        }

        DB::transaction(function () use ($post, $user, $optionIndexes) {
            PlanPostVote::where('plan_post_id', $post->id)
                ->where('user_id', $user->id)
                ->delete();

            foreach ($optionIndexes as $index) {
                PlanPostVote::create([
                    'plan_post_id' => $post->id,
                    'user_id' => $user->id,
                    'option_index' => $index,
                ]);
            }
        });

        $post->load([
            'user:id,name,username,email',
            'votes.user:id,name,username',
        ]);

        return response()->json([
            'message' => 'Vote saved successfully.',
            'post' => $this->attachPollVoteData(
                $this->attachImageUrl($post, $request),
                $request
            ),
        ]);
    }

    private function storeTextOrImagePost(Request $request, Plan $plan, int $userId)
    {
        $content = trim((string) $request->input('content', ''));

        if ($content === '' && !$request->hasFile('image')) {
            return response()->json([
                'message' => 'Please add text or an image before posting.',
            ], 422);
        }

        $request->validate([
            'content' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('plan-posts', 'public');
        }

        $post = PlanPost::create([
            'plan_id' => $plan->id,
            'user_id' => $userId,
            'post_type' => 'text',
            'content' => $content,
            'image_path' => $imagePath,
        ]);

        $post->load('user:id,name,username,email');

        return response()->json([
            'message' => 'Post created successfully.',
            'post' => $this->attachImageUrl($post, $request),
        ], 201);
    }

    private function storePoll(Request $request, Plan $plan, int $userId)
    {
        $validated = $request->validate([
            'poll_question' => ['required', 'string', 'max:255'],
            'poll_options' => ['required', 'array', 'min:2', 'max:10'],
            'poll_options.*' => ['required', 'string', 'max:255'],
            'allow_multiple' => ['nullable', 'boolean'],
            'anonymous' => ['nullable', 'boolean'],
            'allow_members_add_options' => ['nullable', 'boolean'],
            'ends_on' => ['nullable', 'string', 'max:50'],
        ]);

        $cleanOptions = collect($validated['poll_options'])
            ->map(fn ($option) => trim((string) $option))
            ->filter(fn ($option) => $option !== '')
            ->values()
            ->toArray();

        if (count($cleanOptions) < 2) {
            return response()->json([
                'message' => 'Please provide at least two poll options.',
            ], 422);
        }

        $post = PlanPost::create([
            'plan_id' => $plan->id,
            'user_id' => $userId,
            'post_type' => 'poll',
            'content' => $validated['poll_question'],
            'poll_question' => $validated['poll_question'],
            'poll_options' => $cleanOptions,
            'allow_multiple' => $validated['allow_multiple'] ?? false,
            'anonymous' => $validated['anonymous'] ?? true,
            'allow_members_add_options' => $validated['allow_members_add_options'] ?? false,
            'ends_on' => $validated['ends_on'] ?? null,
        ]);

        $post->load([
            'user:id,name,username,email',
            'votes.user:id,name,username',
        ]);

        return response()->json([
            'message' => 'Poll created successfully.',
            'post' => $this->attachPollVoteData(
                $this->attachImageUrl($post, $request),
                $request
            ),
        ], 201);
    }

    private function attachImageUrl(PlanPost $post, Request $request): PlanPost
    {
        $post->image_url = $post->image_path
            ? $request->getSchemeAndHttpHost() . Storage::url($post->image_path)
            : null;

        return $post;
    }

    private function attachPollVoteData(PlanPost $post, Request $request): PlanPost
    {
        if ($post->post_type !== 'poll') {
            return $post;
        }

        $options = $post->poll_options ?? [];

        $voteCounts = array_fill(0, count($options), 0);
        $optionVoters = array_fill(0, count($options), []);

        foreach ($post->votes as $vote) {
            $index = (int) $vote->option_index;

            if (!array_key_exists($index, $voteCounts)) {
                continue;
            }

            $voteCounts[$index]++;

            if ($vote->user) {
                $optionVoters[$index][] = [
                    'id' => $vote->user->id,
                    'name' => $vote->user->name,
                    'username' => $vote->user->username,
                ];
            }
        }

        $totalVoters = $post->votes
            ->pluck('user_id')
            ->unique()
            ->count();

        $votePercentages = array_map(function ($count) use ($totalVoters) {
            if ($totalVoters <= 0) {
                return 0;
            }

            return (int) round(($count / $totalVoters) * 100);
        }, $voteCounts);

        $optionVoterPreviews = [];
        $optionVoterExtraCounts = [];

        foreach ($optionVoters as $voters) {
            $uniqueVoters = collect($voters)
                ->unique('id')
                ->values();

            $preview = $uniqueVoters->take(3)->values()->toArray();
            $extraCount = max($uniqueVoters->count() - 3, 0);

            $optionVoterPreviews[] = $preview;
            $optionVoterExtraCounts[] = $extraCount;
        }

        $post->vote_counts = $voteCounts;
        $post->vote_percentages = $votePercentages;
        $post->total_votes = $totalVoters;
        $post->option_voter_previews = $optionVoterPreviews;
        $post->option_voter_extra_counts = $optionVoterExtraCounts;

        $post->user_votes = $post->votes
            ->where('user_id', $request->user()->id)
            ->pluck('option_index')
            ->map(fn ($index) => (int) $index)
            ->values()
            ->toArray();

        $post->unsetRelation('votes');

        return $post;
    }
}