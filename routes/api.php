<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlanPostController;
use App\Http\Controllers\PlanBudgetController;
use App\Http\Controllers\PlanResponsibilityController;
use App\Http\Controllers\UserSettingsController;

Route::get('/test', function () {
    return response()->json([
        'message' => 'Dinadrawing backend is working',
    ]);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public because it is also used during Sign Up.
Route::post('/check-username', [UserSettingsController::class, 'checkUsername']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    // User Profile and Settings
    Route::get('/user/settings', [UserSettingsController::class, 'show']);
    Route::put('/user/profile', [UserSettingsController::class, 'updateProfile']);
    Route::patch('/user/username', [UserSettingsController::class, 'updateUsername']);
    Route::patch('/user/notifications', [UserSettingsController::class, 'updateNotifications']);
    Route::post('/user/password', [UserSettingsController::class, 'changePassword']);

    // Active Plans
    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::post('/plans/join', [PlanController::class, 'join']);

    // Archived Plans
    Route::get('/archived-plans', [PlanController::class, 'archivedPlans']);
    Route::post('/plans/{plan}/archive', [PlanController::class, 'archive']);
    Route::post('/plans/{plan}/unarchive', [PlanController::class, 'unarchive']);

    // Deleted Plans
    Route::get('/deleted-plans', [PlanController::class, 'deletedPlans']);
    Route::post('/plans/{plan}/restore', [PlanController::class, 'restore']);
    Route::delete('/plans/{plan}/force', [PlanController::class, 'forceDelete']);

    // Single Plan
    Route::get('/plans/{plan}', [PlanController::class, 'show']);
    Route::patch('/plans/{plan}', [PlanController::class, 'update']);
    Route::patch('/plans/{plan}/banner', [PlanController::class, 'updateBanner']);
    Route::delete('/plans/{plan}', [PlanController::class, 'destroy']);
    Route::post('/plans/{plan}/leave', [PlanController::class, 'leave']);

    // Budget Planning
    Route::get('/plans/{plan}/budget', [PlanBudgetController::class, 'show']);
    Route::post('/plans/{plan}/budget', [PlanBudgetController::class, 'store']);
    Route::put('/plans/{plan}/budget', [PlanBudgetController::class, 'update']);
    Route::patch('/plans/{plan}/budget/settings', [PlanBudgetController::class, 'updateSettings']);
    Route::patch('/plans/{plan}/budget/allocations/{allocation}/paid', [PlanBudgetController::class, 'setPaidStatus']);
    Route::delete('/plans/{plan}/budget', [PlanBudgetController::class, 'destroy']);

    // Plan Posts and Feed
    Route::get('/plans/{plan}/posts', [PlanPostController::class, 'index']);
    Route::post('/plans/{plan}/posts', [PlanPostController::class, 'store']);
    Route::delete('/plan-posts/{post}', [PlanPostController::class, 'destroyPost']);
    Route::patch('/plan-posts/{post}/pin', [PlanPostController::class, 'togglePin']);

    // Polls
    Route::post('/plan-posts/{post}/vote', [PlanPostController::class, 'vote']);
    Route::post('/plan-posts/{post}/options', [PlanPostController::class, 'addOption']);
    Route::patch('/plan-posts/{post}/poll', [PlanPostController::class, 'updatePoll']);
    Route::patch('/plan-posts/{post}/voting', [PlanPostController::class, 'toggleVoting']);

    // Who's Doing What / Responsibilities
    Route::post('/plans/{plan}/responsibilities', [PlanResponsibilityController::class, 'store']);
    Route::patch('/plan-posts/{post}/responsibility', [PlanResponsibilityController::class, 'update']);
    Route::patch('/plan-posts/{post}/responsibility/finalized', [PlanResponsibilityController::class, 'toggleFinalized']);
    Route::post('/plan-posts/{post}/responsibility/items', [PlanResponsibilityController::class, 'addItem']);
    Route::post('/responsibility-items/{item}/claim', [PlanResponsibilityController::class, 'claim']);
    Route::delete('/responsibility-items/{item}/claim', [PlanResponsibilityController::class, 'unclaim']);
    Route::patch('/responsibility-items/{item}/contribution', [PlanResponsibilityController::class, 'updateContribution']);
    Route::post('/responsibility-items/{item}/preassign', [PlanResponsibilityController::class, 'preassign']);
    Route::delete('/responsibility-items/{item}/preassign/{assignment}', [PlanResponsibilityController::class, 'removePreassignment']);
    Route::patch('/responsibility-items/{item}/response', [PlanResponsibilityController::class, 'respond']);
});