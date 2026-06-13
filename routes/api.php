<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlanPostController;
use App\Http\Controllers\PlanResponsibilityController;

Route::get('/test', function () {
    return response()->json([
        'message' => 'Dinadrawing backend is working',
    ]);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    // --- Active Plans ---
    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::post('/plans/join', [PlanController::class, 'join']);

    // --- Archived Plans ---
    Route::get('/archived-plans', [PlanController::class, 'archivedPlans']);
    Route::post('/plans/{plan}/archive', [PlanController::class, 'archive']);
    Route::post('/plans/{plan}/unarchive', [PlanController::class, 'unarchive']);

    // --- Deleted Plans ---
    Route::get('/deleted-plans', [PlanController::class, 'deletedPlans']);
    Route::post('/plans/{plan}/restore', [PlanController::class, 'restore']);
    Route::delete('/plans/{plan}/force', [PlanController::class, 'forceDelete']);

    // --- Single Plan ---
    Route::get('/plans/{plan}', [PlanController::class, 'show']);
    Route::patch('/plans/{plan}', [PlanController::class, 'update']);
    Route::patch('/plans/{plan}/banner', [PlanController::class, 'updateBanner']);
    Route::delete('/plans/{plan}', [PlanController::class, 'destroy']);
    Route::post('/plans/{plan}/leave', [PlanController::class, 'leave']);

    // --- Plan Posts / Feed ---
    Route::get('/plans/{plan}/posts', [PlanPostController::class, 'index']);
    Route::post('/plans/{plan}/posts', [PlanPostController::class, 'store']);
    Route::patch('/plan-posts/{post}/pin', [PlanPostController::class, 'togglePin']);
    Route::delete('/plan-posts/{post}', [PlanPostController::class, 'destroyPost']);

    // --- Polls ---
    Route::post('/plan-posts/{post}/vote', [PlanPostController::class, 'vote']);
    Route::post('/plan-posts/{post}/options', [PlanPostController::class, 'addOption']);
    Route::patch('/plan-posts/{post}/poll', [PlanPostController::class, 'updatePoll']);
    Route::patch('/plan-posts/{post}/voting', [PlanPostController::class, 'toggleVoting']);

    // --- Responsibilities: Create and Manage List ---
    Route::post('/plans/{plan}/responsibilities', [PlanResponsibilityController::class, 'store']);
    Route::patch('/plan-posts/{post}/responsibility', [PlanResponsibilityController::class, 'update']);
    Route::patch('/plan-posts/{post}/responsibility/finalized', [PlanResponsibilityController::class, 'toggleFinalized']);
    Route::post('/plan-posts/{post}/responsibility/items', [PlanResponsibilityController::class, 'addItem']);

    // --- Responsibilities: Person-Based Entries ---
    Route::patch('/responsibility-items/{item}/contribution', [PlanResponsibilityController::class, 'updateContribution']);

    // --- Responsibilities: Claim and Unclaim ---
    Route::post('/responsibility-items/{item}/claim', [PlanResponsibilityController::class, 'claim']);
    Route::delete('/responsibility-items/{item}/claim', [PlanResponsibilityController::class, 'unclaim']);

    // --- Responsibilities: Accept or Decline Pre-Assignment ---
    Route::patch('/responsibility-items/{item}/response', [PlanResponsibilityController::class, 'respond']);

    // --- Responsibilities: Creator/Admin Pre-Assignment ---
    Route::post('/responsibility-items/{item}/preassign', [PlanResponsibilityController::class, 'preassign']);
    Route::delete('/responsibility-items/{item}/preassign/{assignment}', [PlanResponsibilityController::class, 'removePreassignment']);
});