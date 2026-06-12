<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlanPostController;

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

    // --- Active Plans Routes ---
    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::post('/plans/join', [PlanController::class, 'join']);

    // --- Archived Plans Management ---
    Route::get('/archived-plans', [PlanController::class, 'archivedPlans']);
    Route::post('/plans/{plan}/archive', [PlanController::class, 'archive']);
    Route::post('/plans/{plan}/unarchive', [PlanController::class, 'unarchive']);

    // --- Trash / Deleted Plans Management ---
    Route::get('/deleted-plans', [PlanController::class, 'deletedPlans']);
    Route::post('/plans/{plan}/restore', [PlanController::class, 'restore']);
    Route::delete('/plans/{plan}/force', [PlanController::class, 'forceDelete']);

    // --- Single Plan Routes ---
    Route::get('/plans/{plan}', [PlanController::class, 'show']);
    Route::patch('/plans/{plan}', [PlanController::class, 'update']);
    Route::patch('/plans/{plan}/banner', [PlanController::class, 'updateBanner']);
    Route::delete('/plans/{plan}', [PlanController::class, 'destroy']);

    // --- Plan Posts / Feed Tab Routes ---
    Route::get('/plans/{plan}/posts', [PlanPostController::class, 'index']);
    Route::post('/plans/{plan}/posts', [PlanPostController::class, 'store']);

    // --- Leave Plan Routes ---
    Route::post('/plans/{plan}/leave', [PlanController::class, 'leave']);

    // --- Plan Post Voting Route ---
    Route::post('/plan-posts/{post}/vote', [PlanPostController::class, 'vote']);
});