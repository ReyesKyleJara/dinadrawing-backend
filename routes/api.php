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
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/user/password', [AuthController::class, 'changePassword']);

    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::get('/plans/{plan}', [PlanController::class, 'show']);
    Route::post('/plans/join', [PlanController::class, 'join']);
    Route::patch('/plans/{plan}/banner', [PlanController::class, 'updateBanner']);

    Route::get('/plans/{plan}/posts', [PlanPostController::class, 'index']);
    Route::post('/plans/{plan}/posts', [PlanPostController::class, 'store']);
});