<?php

namespace App\Http\Controllers;

use App\Models\BlitzPoll;
use App\Models\BlitzPollOption;
use App\Models\BlitzPollVote;
use Illuminate\Http\Request;

class BlitzPollController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            BlitzPoll::with('options', 'votes')->where('user_id', $request->user()->id)->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'duration_seconds' => ['required', 'integer', 'min:1'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_name' => ['required', 'string', 'max:255'],
            'options.*.color' => ['nullable', 'string', 'max:50'],
        ]);

        $poll = BlitzPoll::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'duration_seconds' => $validated['duration_seconds'],
            'status' => 'draft',
        ]);

        foreach ($validated['options'] as $option) {
            $poll->options()->create([
                'option_name' => trim($option['option_name']),
                'color' => $option['color'] ?? null,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Poll created successfully',
            'poll' => $poll->load('options'),
        ], 201);
    }

    public function show(BlitzPoll $poll, Request $request)
    {
        if ($poll->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($poll->load('options', 'votes.user'));
    }

    public function update(Request $request, BlitzPoll $poll)
    {
        if ($poll->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'duration_seconds' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.option_name' => ['required', 'string', 'max:255'],
            'options.*.color' => ['nullable', 'string', 'max:50'],
        ]);

        $poll->update([
            'title' => $validated['title'],
            'duration_seconds' => $validated['duration_seconds'],
            'status' => $validated['status'] ?? $poll->status,
        ]);

        $incomingIds = [];
        foreach ($validated['options'] as $optionData) {
            $option = isset($optionData['id']) && $optionData['id']
                ? $poll->options()->find($optionData['id'])
                : null;

            if ($option) {
                $option->update([
                    'option_name' => trim($optionData['option_name']),
                    'color' => $optionData['color'] ?? null,
                ]);
                $incomingIds[] = $option->id;
            } else {
                $newOption = $poll->options()->create([
                    'option_name' => trim($optionData['option_name']),
                    'color' => $optionData['color'] ?? null,
                ]);
                $incomingIds[] = $newOption->id;
            }
        }

        $poll->options()->whereNotIn('id', $incomingIds)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Poll updated successfully',
            'poll' => $poll->fresh()->load('options', 'votes'),
        ]);
    }

    public function destroy(BlitzPoll $poll, Request $request)
    {
        if ($poll->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $poll->delete();

        return response()->json(['success' => true, 'message' => 'Poll deleted successfully']);
    }

    public function start(BlitzPoll $poll, Request $request)
    {
        if ($poll->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($poll->options()->count() < 2) {
            return response()->json(['message' => 'A poll needs at least 2 options.'], 422);
        }

        $poll->update(['status' => 'active']);

        return response()->json([
            'success' => true,
            'message' => 'Poll started successfully',
            'poll' => $poll->fresh()->load('options'),
        ]);
    }

    public function vote(Request $request, BlitzPoll $poll)
    {
        if ($poll->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'option_id' => ['required', 'integer', 'exists:blitz_poll_options,id'],
        ]);

        $option = $poll->options()->find($validated['option_id']);
        if (!$option) {
            return response()->json(['message' => 'Invalid option.'], 422);
        }

        $alreadyVoted = $poll->votes()->where('user_id', $request->user()->id)->exists();
        if ($alreadyVoted) {
            return response()->json(['message' => 'You have already voted in this poll.'], 422);
        }

        $vote = $poll->votes()->create([
            'option_id' => $option->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vote recorded',
            'vote' => $vote->load('option'),
            'results' => $this->results($poll),
        ]);
    }

    public function results(BlitzPoll $poll, Request $request)
    {
        if ($poll->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->buildResults($poll));
    }

    private function buildResults(BlitzPoll $poll): array
    {
        $options = $poll->options()->withCount('votes')->get();
        $totalVotes = $options->sum('votes_count');
        $winner = $options->sortByDesc('votes_count')->first();

        return [
            'poll' => $poll->load('options'),
            'results' => $options->map(function ($option) use ($totalVotes) {
                return [
                    'id' => $option->id,
                    'option_name' => $option->option_name,
                    'votes' => $option->votes_count,
                    'percentage' => $totalVotes > 0 ? round(($option->votes_count / $totalVotes) * 100, 2) : 0,
                ];
            })->values(),
            'total_votes' => $totalVotes,
            'winner' => $winner ? [
                'id' => $winner->id,
                'option_name' => $winner->option_name,
                'votes' => $winner->votes_count,
            ] : null,
        ];
    }
}
