<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Plan;
use App\Models\PlanPost;
use App\Models\PlanPostVote;
use App\Services\ActivityNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PlanPostController extends Controller
{
    public function index(
    Request $request,
    Plan $plan
) {
    $user = $request->user();

    if ($plan->is_deleted) {
        return response()->json([
            'message' => 'Plan not found.',
        ], 404);
    }

    $isMember = $plan->members()
        ->where('users.id', $user->id)
        ->exists();

    $isPlanAdmin =
        (int) $plan->admin_id ===
        (int) $user->id;

    if (!$isMember && !$isPlanAdmin) {
        return response()->json([
            'message' =>
                'You are not allowed to view posts in this plan.',
        ], 403);
    }

    $posts = PlanPost::where(
        'plan_id',
        $plan->id
    )
        ->with([
            'user:id,name,username,email',

            'votes.user:id,name,username',

            'responsibilityItems.member:id,name,username,email',

            'responsibilityItems.assignments.user:id,name,username,email',
        ])
    ->withCount([
        'comments as comment_count',

        ])
        ->orderByDesc('is_pinned')
        ->latest()
        ->get()
        ->map(function ($post) use (
            $request,
            $plan
        ) {
            return $this->formatPostForResponse(
                $post,
                $request,
                $plan
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

        $votingState = $this->getPollVotingState($post);

        if (!$votingState['can_vote']) {
            return response()->json([
                'message' => $votingState['message'],
                'voting_status' => $votingState['status'],
            ], 422);
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

        ActivityNotifier::deleteByKey(
            'poll:' . $post->id . ':vote',
            (int) $user->id
        );

        $this->notifyPollOwnerAboutVotes(
            $post,
            $plan,
            (int) $user->id
        );

        $post->load([
            'user:id,name,username,email',
            'votes.user:id,name,username',
        ]);

        return response()->json([
            'message' => 'Vote saved successfully.',
            'post' => $this->formatPostForResponse($post, $request, $plan),
        ]);
    }

    public function addOption(Request $request, PlanPost $post)
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
                'message' => 'You are not allowed to add options to this poll.',
            ], 403);
        }

        $isPollOwner = (int) $post->user_id === (int) $user->id;
        $isPlanAdmin = (int) $plan->admin_id === (int) $user->id;

        if (!$post->allow_members_add_options && !$isPollOwner && !$isPlanAdmin) {
            return response()->json([
                'message' => 'This poll does not allow members to add options.',
            ], 403);
        }

        $validated = $request->validate([
            'option' => ['required', 'string', 'max:255'],
        ]);

        $newOption = trim($validated['option']);

        if ($newOption === '') {
            return response()->json([
                'message' => 'Please enter an option.',
            ], 422);
        }

        $options = $post->poll_options ?? [];

        if (count($options) >= 10) {
            return response()->json([
                'message' => 'This poll already has the maximum number of options.',
            ], 422);
        }

        $optionAlreadyExists = collect($options)
            ->map(fn ($option) => strtolower(trim((string) $option)))
            ->contains(strtolower($newOption));

        if ($optionAlreadyExists) {
            return response()->json([
                'message' => 'This option already exists.',
            ], 422);
        }

        $options[] = $newOption;

        $post->update([
            'poll_options' => $options,
        ]);

        $votedUserIds = $post->votes()
            ->where('user_id', '!=', $user->id)
            ->pluck('user_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->all();

        ActivityNotifier::notifyUsers(
            recipientUserIds: $votedUserIds,
            actorUserId: (int) $user->id,
            type: 'poll_vote_review_required',
            planId: (int) $plan->id,
            planPostId: (int) $post->id,
            data: [
                'activity_tab' => 'action_required',
                'requires_action' => true,
                'action' => 'review_vote',
                'poll_question' => (string) $post->poll_question,
                'new_option' => $newOption,
            ],
            notificationKey: 'poll:' . $post->id . ':vote',
            replaceExisting: true,
        );

        $post->load([
            'user:id,name,username,email',
            'votes.user:id,name,username',
        ]);

        return response()->json([
            'message' => 'Option added successfully.',
            'post' => $this->formatPostForResponse($post, $request, $plan),
        ]);
    }

    public function togglePin(Request $request, PlanPost $post)
    {
        $user = $request->user();
        $plan = $post->plan;

        if (!$plan || $plan->is_deleted) {
            return response()->json([
                'message' => 'Plan not found.',
            ], 404);
        }

        if ((int) $plan->admin_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Only the plan admin can pin or unpin posts.',
            ], 403);
        }

        $validated = $request->validate([
            'is_pinned' => ['required', 'boolean'],
        ]);

        $post->update([
            'is_pinned' => $validated['is_pinned'],
        ]);

        $post->load([
            'user:id,name,username,email',
            'votes.user:id,name,username',
        ]);

        return response()->json([
            'message' => $post->is_pinned ? 'Post pinned successfully.' : 'Post unpinned successfully.',
            'post' => $this->formatPostForResponse($post, $request, $plan),
        ]);
    }

    public function toggleVoting(Request $request, PlanPost $post)
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

        if (!$this->canManagePoll($post, $plan, $user->id)) {
            return response()->json([
                'message' => 'You are not allowed to manage this poll.',
            ], 403);
        }

        $validated = $request->validate([
            'is_voting_closed' => ['required', 'boolean'],
        ]);

        $updates = [
            'is_voting_closed' => $validated['is_voting_closed'],
        ];

        if (!$validated['is_voting_closed']) {
            $updates['finalized_option_index'] = null;
            $updates['finalized_at'] = null;
            $updates['applied_to_plan_at'] = null;
        }

        $post->update($updates);

        if ($post->is_voting_closed) {
            ActivityNotifier::deleteByKey(
                'poll:' . $post->id . ':vote'
            );

            ActivityNotifier::notifyPlan(
                plan: $plan,
                actorUserId: (int) $user->id,
                type: 'poll_voting_closed',
                data: [
                    'activity_tab' => 'notifications',
                    'requires_action' => false,
                    'poll_question' => (string) $post->poll_question,
                ],
                planPostId: (int) $post->id,
                excludeUserId: (int) $user->id,
                notificationKey: 'poll:' . $post->id . ':closed',
                replaceExisting: true,
            );
        } else {
            $this->notifyEligiblePollVoters(
                $plan,
                $post,
                (int) $user->id,
                'poll_vote_required'
            );
        }

        $post->load([
            'user:id,name,username,email',
            'votes.user:id,name,username',
        ]);

        return response()->json([
            'message' => $post->is_voting_closed ? 'Voting closed successfully.' : 'Voting reopened successfully.',
            'post' => $this->formatPostForResponse($post, $request, $plan),
        ]);
    }

    public function updatePoll(Request $request, PlanPost $post)
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

        if (!$this->canManagePoll($post, $plan, $user->id)) {
            return response()->json([
                'message' => 'You are not allowed to edit this poll.',
            ], 403);
        }

        $validated = $request->validate([
            'poll_question' => ['nullable', 'string', 'max:255'],
            'poll_kind' => [
                'nullable',
                Rule::in(['general', 'date', 'location']),
            ],
            'poll_options' => ['nullable', 'array', 'min:2', 'max:10'],
            'poll_options.*' => ['required', 'string', 'max:255'],
            'allow_multiple' => ['nullable', 'boolean'],
            'anonymous' => ['nullable', 'boolean'],
            'allow_members_add_options' => ['nullable', 'boolean'],
            'ends_on' => ['nullable', 'string', 'max:50'],
            'voting_starts_at' => ['nullable', 'date'],
            'voting_ends_at' => ['nullable', 'date', 'after_or_equal:voting_starts_at'],
        ]);

        $totalVoters = $post->votes()
            ->pluck('user_id')
            ->unique()
            ->count();

        $hasVotes = $totalVoters > 0;

        $updates = [];

        if (array_key_exists('poll_kind', $validated)) {
            if ($hasVotes || $post->finalized_at !== null) {
                return response()->json([
                    'message' => 'Poll type cannot be changed after voting has started.',
                ], 422);
            }

            $updates['poll_kind'] = $validated['poll_kind'];
        }

        if (array_key_exists('poll_question', $validated)) {
            $newQuestion = trim((string) $validated['poll_question']);

            if ($newQuestion === '') {
                return response()->json([
                    'message' => 'Please enter a poll question.',
                ], 422);
            }

            if ($hasVotes && $newQuestion !== $post->poll_question) {
                return response()->json([
                    'message' => 'Poll question cannot be edited after voting has started.',
                ], 422);
            }

            $updates['poll_question'] = $newQuestion;
            $updates['content'] = $newQuestion;
        }

        if (array_key_exists('poll_options', $validated)) {
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

            $currentOptions = $post->poll_options ?? [];

            if ($hasVotes) {
                if (count($cleanOptions) < count($currentOptions)) {
                    return response()->json([
                        'message' => 'Options cannot be removed after voting has started.',
                    ], 422);
                }

                foreach ($currentOptions as $index => $currentOption) {
                    if (!array_key_exists($index, $cleanOptions) || $cleanOptions[$index] !== $currentOption) {
                        return response()->json([
                            'message' => 'Options cannot be edited after voting has started.',
                        ], 422);
                    }
                }
            }

            $hasDuplicates = collect($cleanOptions)
                ->map(fn ($option) => strtolower($option))
                ->duplicates()
                ->isNotEmpty();

            if ($hasDuplicates) {
                return response()->json([
                    'message' => 'Poll options must be unique.',
                ], 422);
            }

            $updates['poll_options'] = $cleanOptions;
        }

        if (array_key_exists('allow_multiple', $validated)) {
            if ($hasVotes && (bool) $validated['allow_multiple'] !== (bool) $post->allow_multiple) {
                return response()->json([
                    'message' => 'Multiple voting cannot be changed after voting has started.',
                ], 422);
            }

            $updates['allow_multiple'] = $validated['allow_multiple'];
        }

        if (array_key_exists('anonymous', $validated)) {
            $updates['anonymous'] = $validated['anonymous'];
        }

        if (array_key_exists('allow_members_add_options', $validated)) {
            $updates['allow_members_add_options'] = $validated['allow_members_add_options'];
        }

        if (array_key_exists('ends_on', $validated)) {
            $updates['ends_on'] = $validated['ends_on'];
        }

        if (array_key_exists('voting_starts_at', $validated)) {
            $updates['voting_starts_at'] = $validated['voting_starts_at'];
        }

        if (array_key_exists('voting_ends_at', $validated)) {
            $updates['voting_ends_at'] = $validated['voting_ends_at'];
        }

        $post->update($updates);

        $post->load([
            'user:id,name,username,email',
            'votes.user:id,name,username',
        ]);

        return response()->json([
            'message' => 'Poll updated successfully.',
            'post' => $this->formatPostForResponse($post, $request, $plan),
        ]);
    }

    public function finalizePoll(
        Request $request,
        PlanPost $post
    ) {
        $user = $request->user();
        $plan = $post->plan;

        if ($post->post_type !== 'poll') {
            return response()->json([
                'message' => 'This post is not a poll.',
            ], 422);
        }

        if (!$plan || $plan->is_deleted) {
            return response()->json([
                'message' => 'Plan not found.',
            ], 404);
        }

        if ((int) $plan->admin_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Only the plan admin can finalize this poll.',
            ], 403);
        }

        $validated = $request->validate([
            'option_index' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ]);

        $options = $post->poll_options ?? [];

        if (count($options) < 1) {
            return response()->json([
                'message' => 'This poll has no options to finalize.',
            ], 422);
        }

        $voteCounts = array_fill(
            0,
            count($options),
            0
        );

        foreach ($post->votes as $vote) {
            $index = (int) $vote->option_index;

            if (array_key_exists($index, $voteCounts)) {
                $voteCounts[$index]++;
            }
        }

        $maxVotes = max($voteCounts);
        $topIndexes = [];

        foreach ($voteCounts as $index => $count) {
            if ($count === $maxVotes) {
                $topIndexes[] = $index;
            }
        }

        $selectedIndex = array_key_exists(
            'option_index',
            $validated
        )
            ? (int) $validated['option_index']
            : null;

        if (
            $selectedIndex !== null &&
            !array_key_exists($selectedIndex, $options)
        ) {
            return response()->json([
                'message' => 'Invalid final poll option.',
            ], 422);
        }

        if ($selectedIndex === null) {
            if (count($topIndexes) !== 1) {
                return response()->json([
                    'message' => $maxVotes === 0
                        ? 'Choose the final result before finalizing this poll.'
                        : 'The poll ended in a tie. Choose the final result.',
                    'requires_selection' => true,
                    'tied_option_indexes' => $topIndexes,
                    'vote_counts' => $voteCounts,
                ], 422);
            }

            $selectedIndex = $topIndexes[0];
        } elseif (
            $maxVotes > 0 &&
            !in_array($selectedIndex, $topIndexes, true)
        ) {
            return response()->json([
                'message' => 'Choose one of the highest-voted options.',
                'requires_selection' => true,
                'tied_option_indexes' => $topIndexes,
                'vote_counts' => $voteCounts,
            ], 422);
        }

        $post->update([
            'is_voting_closed' => true,
            'finalized_option_index' => $selectedIndex,
            'finalized_at' => now(),
            'applied_to_plan_at' => null,
        ]);

        ActivityNotifier::deleteByKey(
            'poll:' . $post->id . ':vote'
        );

        ActivityNotifier::notifyPlan(
            plan: $plan,
            actorUserId: (int) $user->id,
            type: 'poll_finalized',
            data: [
                'activity_tab' => 'notifications',
                'requires_action' => false,
                'poll_question' => (string) $post->poll_question,
                'winning_option' => (string) $options[$selectedIndex],
                'winning_option_index' => (int) $selectedIndex,
                'poll_kind' => (string) ($post->poll_kind ?? 'general'),
            ],
            planPostId: (int) $post->id,
            excludeUserId: (int) $user->id,
            notificationKey: 'poll:' . $post->id . ':finalized',
            replaceExisting: true,
        );

        $post->load([
            'user:id,name,username,email',
            'votes.user:id,name,username',
        ]);

        return response()->json([
            'message' => 'Poll finalized successfully.',
            'post' => $this->formatPostForResponse(
                $post,
                $request,
                $plan
            ),
        ]);
    }

    public function applyPollResult(
        Request $request,
        PlanPost $post
    ) {
        $user = $request->user();
        $plan = $post->plan;

        if ($post->post_type !== 'poll') {
            return response()->json([
                'message' => 'This post is not a poll.',
            ], 422);
        }

        if (!$plan || $plan->is_deleted) {
            return response()->json([
                'message' => 'Plan not found.',
            ], 404);
        }

        if ((int) $plan->admin_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Only the plan admin can apply a poll result.',
            ], 403);
        }

        if (
            $post->finalized_at === null ||
            $post->finalized_option_index === null
        ) {
            return response()->json([
                'message' => 'Finalize the poll before applying its result.',
            ], 422);
        }

        if (!in_array($post->poll_kind, ['date', 'location'], true)) {
            return response()->json([
                'message' => 'Only date and location poll results can be applied to a plan.',
            ], 422);
        }

        $options = $post->poll_options ?? [];
        $selectedIndex = (int) $post->finalized_option_index;
        $selectedOption = $options[$selectedIndex] ?? null;

        if ($selectedOption === null) {
            return response()->json([
                'message' => 'The finalized option is no longer available.',
            ], 422);
        }

        if ($post->poll_kind === 'date') {
            try {
                $plan->update([
                    'plan_date' => Carbon::parse(
                        $selectedOption
                    )->toDateString(),
                ]);
            } catch (\Throwable $error) {
                return response()->json([
                    'message' => 'The winning option is not a valid date.',
                ], 422);
            }
        } else {
            $location = trim((string) $selectedOption);

            if ($location === '') {
                return response()->json([
                    'message' => 'The winning location is empty.',
                ], 422);
            }

            $plan->update([
                'location' => $location,
                'latitude' => null,
                'longitude' => null,
            ]);
        }

        $post->update([
            'applied_to_plan_at' => now(),
        ]);

        ActivityNotifier::notifyPlan(
            plan: $plan->fresh(),
            actorUserId: (int) $user->id,
            type: $post->poll_kind === 'date'
                ? 'plan_date_changed'
                : 'plan_location_changed',
            data: [
                'activity_tab' => 'notifications',
                'requires_action' => false,
                'change_source' => 'poll_result',
                'poll_question' => (string) $post->poll_question,
                'new_value' => (string) $selectedOption,
            ],
            planPostId: (int) $post->id,
            excludeUserId: (int) $user->id,
            notificationKey: 'poll:' . $post->id . ':applied',
            replaceExisting: true,
        );

        $post->load([
            'user:id,name,username,email',
            'votes.user:id,name,username',
        ]);

        return response()->json([
            'message' => $post->poll_kind === 'date'
                ? 'Winning date applied to the plan.'
                : 'Winning location applied to the plan.',
            'plan' => $plan->fresh()->load([
                'admin',
                'members',
            ]),
            'post' => $this->formatPostForResponse(
                $post,
                $request,
                $plan->fresh()
            ),
        ]);
    }

    public function destroyPost(
    Request $request,
    PlanPost $post
) {
    $user = $request->user();
    $plan = $post->plan;

    if (!$plan || $plan->is_deleted) {
        return response()->json([
            'message' => 'Plan not found.',
        ], 404);
    }

    $isPlanAdmin =
        (int) $plan->admin_id ===
        (int) $user->id;

    $isPostOwner =
        (int) $post->user_id ===
        (int) $user->id;

    $ownerCanDelete =
        $isPostOwner &&
        in_array(
            $post->post_type,
            [
                'poll',
                'responsibility',
            ],
            true
        );

    if (!$isPlanAdmin && !$ownerCanDelete) {
        return response()->json([
            'message' =>
                'You are not allowed to delete this post.',
        ], 403);
    }

    if ($post->image_path) {
        Storage::disk('public')->delete(
            $post->image_path
        );
    }

    /*
     * Responsibility items and assignments
     * are deleted automatically by cascade.
     */
    $post->delete();

    return response()->json([
        'message' =>
            'Post deleted successfully.',
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
            'is_pinned' => false,
        ]);

        $post->load('user:id,name,username,email');

        return response()->json([
            'message' => 'Post created successfully.',
            'post' => $this->formatPostForResponse($post, $request, $plan),
        ], 201);
    }

    private function storePoll(Request $request, Plan $plan, int $userId)
    {
        $validated = $request->validate([
            'poll_question' => ['required', 'string', 'max:255'],
            'poll_kind' => [
                'nullable',
                Rule::in(['general', 'date', 'location']),
            ],
            'poll_options' => ['required', 'array', 'min:2', 'max:10'],
            'poll_options.*' => ['required', 'string', 'max:255'],
            'allow_multiple' => ['nullable', 'boolean'],
            'anonymous' => ['nullable', 'boolean'],
            'allow_members_add_options' => ['nullable', 'boolean'],
            'ends_on' => ['nullable', 'string', 'max:50'],
            'voting_starts_at' => ['nullable', 'date'],
            'voting_ends_at' => ['nullable', 'date', 'after_or_equal:voting_starts_at'],
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
            'poll_kind' => $validated['poll_kind'] ?? 'general',
            'poll_options' => $cleanOptions,
            'allow_multiple' => $validated['allow_multiple'] ?? false,
            'anonymous' => $validated['anonymous'] ?? true,
            'allow_members_add_options' => $validated['allow_members_add_options'] ?? false,
            'ends_on' => $validated['ends_on'] ?? null,
            'is_pinned' => false,
            'voting_starts_at' => $validated['voting_starts_at'] ?? null,
            'voting_ends_at' => $validated['voting_ends_at'] ?? null,
            'is_voting_closed' => false,
        ]);

        $post->load([
            'user:id,name,username,email',
            'votes.user:id,name,username',
        ]);

        $this->notifyPollCreated(
            $plan,
            $post,
            $userId
        );

        return response()->json([
            'message' => 'Poll created successfully.',
            'post' => $this->formatPostForResponse($post, $request, $plan),
        ], 201);
    }

    private function formatPostForResponse(
    PlanPost $post,
    Request $request,
    Plan $plan
): PlanPost {
    $this->attachImageUrl(
        $post,
        $request
    );

    $this->attachActionPermissions(
        $post,
        $request,
        $plan
    );

    $post->comment_count = (int) (
        $post->comment_count ??
        $post->comments()->count()
    );

    if ($post->post_type === 'poll') {
        $this->attachPollVoteData(
            $post,
            $request
        );
    }

    if (
        $post->post_type ===
        'responsibility'
    ) {
        $post->loadMissing([
            'responsibilityItems.member:id,name,username,email',

            'responsibilityItems.assignments.user:id,name,username,email',
        ]);

        $totalCount = 0;
        $filledCount = 0;

        foreach (
            $post->responsibilityItems
            as $item
        ) {
            $acceptedCount =
                $item->assignments
                    ->where(
                        'status',
                        'accepted'
                    )
                    ->count();

            $pendingCount =
                $item->assignments
                    ->where(
                        'status',
                        'pending'
                    )
                    ->count();

            $currentUserAssignment =
                $item->assignments
                    ->firstWhere(
                        'user_id',
                        $request->user()->id
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
                    (int) $request->user()->id;
            }

            $item->member_display_name =
                $item->member?->name
                ?? $item->title;

            $item->member_username =
                $item->member?->username;

            $item->is_current_user_member =
                $item->member_user_id !== null &&
                (int) $item->member_user_id ===
                (int) $request->user()->id;

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

            if (
                $post->responsibility_mode ===
                'person_based'
            ) {
                $totalCount++;

                if (
                    trim(
                        (string)
                        $item->contribution
                    ) !== ''
                ) {
                    $filledCount++;
                }
            } else {
                $totalCount +=
                    $item->slots;

                $filledCount +=
                    $acceptedCount;
            }
        }

        $post->responsibility_total_count =
            $totalCount;

        $post->responsibility_filled_count =
            $filledCount;
    }

    return $post;
}

    private function attachImageUrl(PlanPost $post, Request $request): PlanPost
    {
        $post->image_url = $post->image_path
            ? $request->getSchemeAndHttpHost() . Storage::url($post->image_path)
            : null;

        return $post;
    }

    private function attachActionPermissions(
    PlanPost $post,
    Request $request,
    Plan $plan
): PlanPost {
    $user = $request->user();

    $isPlanAdmin =
        (int) $plan->admin_id ===
        (int) $user->id;

    $isPostOwner =
        (int) $post->user_id ===
        (int) $user->id;

    $isPoll =
        $post->post_type === 'poll';

    $isResponsibility =
        $post->post_type ===
        'responsibility';

    $canManageResponsibility =
        $isResponsibility &&
        (
            $isPlanAdmin ||
            $isPostOwner
        );

    $post->is_pinned_value =
        (bool) $post->is_pinned;

    $post->is_plan_admin =
        $isPlanAdmin;

    $post->is_post_owner =
        $isPostOwner;

    $post->can_pin_post =
        $isPlanAdmin;

    $post->can_edit_poll =
        $isPoll &&
        (
            $isPlanAdmin ||
            $isPostOwner
        );

    $post->can_toggle_voting =
        $isPoll &&
        (
            $isPlanAdmin ||
            $isPostOwner
        );

    $post->can_finalize_poll =
        $isPoll &&
        $isPlanAdmin;

    $post->can_apply_poll_result =
        $isPoll &&
        $isPlanAdmin &&
        in_array(
            $post->poll_kind,
            ['date', 'location'],
            true
        ) &&
        $post->finalized_at !== null &&
        $post->applied_to_plan_at === null;

    $post->can_manage_responsibility =
        $canManageResponsibility;

    $post->can_finalize_responsibility =
        $canManageResponsibility;

    $post->can_add_responsibility_items =
        $isResponsibility &&
        !$post->responsibility_is_finalized &&
        (
            $canManageResponsibility ||
            $post->
                responsibility_allow_member_items
        );

    $post->can_delete_post =
        $isPlanAdmin ||
        (
            $isPostOwner &&
            (
                $isPoll ||
                $isResponsibility
            )
        );

    return $post;
}

    private function attachPollVoteData(PlanPost $post, Request $request): PlanPost
    {
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

        $votingState = $this->getPollVotingState($post);

        $post->vote_counts = $voteCounts;
        $post->vote_percentages = $votePercentages;
        $post->total_votes = $totalVoters;
        $post->option_voter_previews = $optionVoterPreviews;
        $post->option_voter_extra_counts = $optionVoterExtraCounts;

        $post->voting_starts_at_value = optional($post->voting_starts_at)->toIso8601String();
        $post->voting_ends_at_value = optional($post->voting_ends_at)->toIso8601String();
        $post->is_voting_closed_value = (bool) $post->is_voting_closed;
        $post->voting_status = $votingState['status'];
        $post->can_vote = $votingState['can_vote'];
        $post->voting_message = $votingState['message'];

        $finalizedIndex = $post->finalized_option_index;

        $post->poll_kind_value = $post->poll_kind ?? 'general';
        $post->is_poll_finalized = $post->finalized_at !== null;
        $post->finalized_option_index_value = $finalizedIndex;
        $post->finalized_option_value =
            $finalizedIndex !== null &&
            array_key_exists($finalizedIndex, $options)
                ? $options[$finalizedIndex]
                : null;
        $post->finalized_at_value = optional(
            $post->finalized_at
        )->toIso8601String();
        $post->applied_to_plan_at_value = optional(
            $post->applied_to_plan_at
        )->toIso8601String();
        $post->is_applied_to_plan =
            $post->applied_to_plan_at !== null;

        $post->user_votes = $post->votes
            ->where('user_id', $request->user()->id)
            ->pluck('option_index')
            ->map(fn ($index) => (int) $index)
            ->values()
            ->toArray();

        $post->unsetRelation('votes');

        return $post;
    }

    private function getPollVotingState(PlanPost $post): array
    {
        $now = now();

        if ($post->is_voting_closed) {
            return [
                'status' => 'closed',
                'can_vote' => false,
                'message' => 'Voting is closed.',
            ];
        }

        if ($post->voting_starts_at && $now->lt($post->voting_starts_at)) {
            return [
                'status' => 'scheduled',
                'can_vote' => false,
                'message' => '',
            ];
        }

        if ($post->voting_ends_at && $now->gt($post->voting_ends_at)) {
            return [
                'status' => 'closed',
                'can_vote' => false,
                'message' => '',
            ];
        }

        return [
            'status' => 'open',
            'can_vote' => true,
            'message' => '',
        ];
    }

    private function notifyPollCreated(
        Plan $plan,
        PlanPost $post,
        int $creatorUserId
    ): void {
        $state = $this->getPollVotingState($post);

        if ($state['status'] === 'open') {
            $this->notifyEligiblePollVoters(
                $plan,
                $post,
                $creatorUserId,
                'poll_vote_required'
            );

            return;
        }

        ActivityNotifier::notifyPlan(
            plan: $plan,
            actorUserId: $creatorUserId,
            type: 'poll_scheduled',
            data: [
                'activity_tab' => 'notifications',
                'requires_action' => false,
                'poll_question' => (string) $post->poll_question,
                'voting_starts_at' => optional($post->voting_starts_at)->toISOString(),
            ],
            planPostId: (int) $post->id,
            excludeUserId: $creatorUserId,
            notificationKey: 'poll:' . $post->id . ':scheduled',
            replaceExisting: true,
        );
    }

    private function notifyEligiblePollVoters(
        Plan $plan,
        PlanPost $post,
        int $actorUserId,
        string $type
    ): void {
        $votedUserIds = $post->votes()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $recipientIds = collect(
            ActivityNotifier::planRecipientIds(
                $plan,
                $actorUserId
            )
        )->reject(
            fn ($id) => in_array((int) $id, $votedUserIds, true)
        );

        ActivityNotifier::notifyUsers(
            recipientUserIds: $recipientIds,
            actorUserId: $actorUserId,
            type: $type,
            planId: (int) $plan->id,
            planPostId: (int) $post->id,
            data: [
                'activity_tab' => 'action_required',
                'requires_action' => true,
                'action' => 'vote',
                'poll_question' => (string) $post->poll_question,
                'poll_kind' => (string) ($post->poll_kind ?? 'general'),
                'voting_ends_at' => optional($post->voting_ends_at)->toISOString(),
            ],
            notificationKey: 'poll:' . $post->id . ':vote',
            replaceExisting: true,
        );
    }

    private function notifyPollOwnerAboutVotes(
        PlanPost $post,
        Plan $plan,
        int $actorUserId
    ): void {
        $ownerUserId = (int) $post->user_id;

        if ($ownerUserId === $actorUserId) {
            return;
        }

        $totalVoters = $post->votes()
            ->pluck('user_id')
            ->unique()
            ->count();

        ActivityNotifier::notifyUser(
            recipientUserId: $ownerUserId,
            actorUserId: $actorUserId,
            type: 'poll_votes_received',
            planId: (int) $plan->id,
            planPostId: (int) $post->id,
            data: [
                'activity_tab' => 'notifications',
                'requires_action' => false,
                'poll_question' => (string) $post->poll_question,
                'vote_count' => (int) $totalVoters,
            ],
            notificationKey: 'poll:' . $post->id . ':votes',
            replaceExisting: true,
        );
    }

    private function canManagePoll(PlanPost $post, Plan $plan, int $userId): bool
    {
        $isPlanAdmin = (int) $plan->admin_id === (int) $userId;
        $isPostOwner = (int) $post->user_id === (int) $userId;

        return $isPlanAdmin || $isPostOwner;
    }
}