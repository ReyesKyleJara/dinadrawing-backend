<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BlitzPollController;
use App\Http\Controllers\DecisionWheelController;
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

    Route::get('/wheels', [DecisionWheelController::class, 'index']);
    Route::post('/wheels', [DecisionWheelController::class, 'store']);
    Route::get('/wheels/{wheel}', [DecisionWheelController::class, 'show']);
    Route::put('/wheels/{wheel}', [DecisionWheelController::class, 'update']);
    Route::delete('/wheels/{wheel}', [DecisionWheelController::class, 'destroy']);
    Route::delete('/wheels/options/{option}', [DecisionWheelController::class, 'deleteOption']);
    Route::post('/wheels/{wheel}/shuffle', [DecisionWheelController::class, 'shuffle']);
    Route::post('/wheels/{wheel}/sort', [DecisionWheelController::class, 'sort']);
    Route::post('/wheels/{wheel}/spin', [DecisionWheelController::class, 'spin']);

    Route::get('/polls', [BlitzPollController::class, 'index']);
    Route::post('/polls', [BlitzPollController::class, 'store']);
    Route::get('/polls/{poll}', [BlitzPollController::class, 'show']);
    Route::put('/polls/{poll}', [BlitzPollController::class, 'update']);
    Route::delete('/polls/{poll}', [BlitzPollController::class, 'destroy']);
    Route::post('/polls/{poll}/start', [BlitzPollController::class, 'start']);
    Route::post('/polls/{poll}/vote', [BlitzPollController::class, 'vote']);
    Route::get('/polls/{poll}/results', [BlitzPollController::class, 'results']);

    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::get('/plans/{plan}', [PlanController::class, 'show']);
    Route::post('/plans/join', [PlanController::class, 'join']);
    Route::patch('/plans/{plan}/banner', [PlanController::class, 'updateBanner']);

    Route::get('/plans/{plan}/posts', [PlanPostController::class, 'index']);
    Route::post('/plans/{plan}/posts', [PlanPostController::class, 'store']);
});