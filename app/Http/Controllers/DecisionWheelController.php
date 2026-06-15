<?php

namespace App\Http\Controllers;

use App\Models\DecisionWheel;
use App\Models\DecisionWheelOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DecisionWheelController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            DecisionWheel::with('options')->where('user_id', $request->user()->id)->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_name' => ['required', 'string', 'max:255'],
            'options.*.color' => ['nullable', 'string', 'max:50'],
        ]);

        $wheel = DecisionWheel::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
        ]);

        foreach ($validated['options'] as $index => $option) {
            $wheel->options()->create([
                'option_name' => trim($option['option_name']),
                'color' => $option['color'] ?? null,
                'sort_order' => $index + 1,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Wheel created successfully',
            'wheel' => $wheel->load('options'),
        ], 201);
    }

    public function show(DecisionWheel $wheel, Request $request)
    {
        if ($wheel->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($wheel->load('options'));
    }

    public function update(Request $request, DecisionWheel $wheel)
    {
        if ($wheel->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.option_name' => ['required', 'string', 'max:255'],
            'options.*.color' => ['nullable', 'string', 'max:50'],
        ]);

        $wheel->update(['title' => $validated['title']]);

        $existing = $wheel->options()->pluck('id');
        $incomingIds = [];

        foreach ($validated['options'] as $index => $optionData) {
            $option = isset($optionData['id']) && $optionData['id']
                ? $wheel->options()->find($optionData['id'])
                : null;

            if ($option) {
                $option->update([
                    'option_name' => trim($optionData['option_name']),
                    'color' => $optionData['color'] ?? null,
                    'sort_order' => $index + 1,
                ]);
                $incomingIds[] = $option->id;
            } else {
                $newOption = $wheel->options()->create([
                    'option_name' => trim($optionData['option_name']),
                    'color' => $optionData['color'] ?? null,
                    'sort_order' => $index + 1,
                ]);
                $incomingIds[] = $newOption->id;
            }
        }

        $wheel->options()->whereNotIn('id', $incomingIds)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Wheel updated successfully',
            'wheel' => $wheel->fresh()->load('options'),
        ]);
    }

    public function destroy(DecisionWheel $wheel, Request $request)
    {
        if ($wheel->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $wheel->delete();

        return response()->json(['success' => true, 'message' => 'Wheel deleted successfully']);
    }

    public function deleteOption(\App\Models\DecisionWheelOption $option, Request $request)
    {
        Log::info('DELETE OPTION', ['option_id' => $option->id, 'wheel_id' => $option->wheel_id]);

        if ($option->wheel->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $option->delete();

        return response()->json([
            'success' => true,
            'message' => 'Option deleted successfully',
            'wheel' => $option->wheel()->with('options')->first(),
        ]);
    }

    public function shuffle(DecisionWheel $wheel, Request $request)
    {
        Log::info('SHUFFLE OPTIONS', ['wheel_id' => $wheel->id]);

        if ($wheel->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $options = $wheel->options()->orderBy('sort_order')->get()->values()->all();
        shuffle($options);

        foreach ($options as $index => $option) {
            $option->update(['sort_order' => $index + 1]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Options shuffled successfully',
            'options' => $wheel->fresh()->options()->orderBy('sort_order')->get(),
        ]);
    }

    public function sort(DecisionWheel $wheel, Request $request)
    {
        Log::info('SORT OPTIONS', ['wheel_id' => $wheel->id]);

        if ($wheel->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $options = $wheel->options()->orderBy('option_name')->get()->values()->all();
        foreach ($options as $index => $option) {
            $option->update(['sort_order' => $index + 1]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Options sorted successfully',
            'options' => $wheel->fresh()->options()->orderBy('sort_order')->get(),
        ]);
    }

    public function spin(DecisionWheel $wheel, Request $request)
    {
        if ($wheel->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $options = $wheel->options()->get();
        if ($options->isEmpty()) {
            return response()->json(['message' => 'Wheel must contain at least one option.'], 422);
        }

        $winner = $options->random();

        Log::info('Wheel spin result', ['wheel_id' => $wheel->id, 'winner_id' => $winner->id]);

        return response()->json([
            'success' => true,
            'winner' => [
                'id' => $winner->id,
                'option_name' => $winner->option_name,
                'color' => $winner->color,
            ],
        ]);
    }
}
