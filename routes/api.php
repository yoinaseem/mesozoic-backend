<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomTypeController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::apiResource('users', UserController::class);

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Hotels – read-only public, mutations protected
Route::apiResource('hotels', HotelController::class)->only(['index', 'show']);
Route::middleware('auth:sanctum')->apiResource('hotels', HotelController::class)->except(['index', 'show']);

// Room types & rooms – nested under hotels, scoped bindings
Route::scopeBindings()->prefix('hotels/{hotel}')->group(function () {
    Route::apiResource('room-types', RoomTypeController::class)->only(['index', 'show']);
    Route::apiResource('rooms', RoomController::class)->only(['index', 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::apiResource('room-types', RoomTypeController::class)->except(['index', 'show']);
        Route::apiResource('rooms', RoomController::class)->except(['index', 'show']);
    });
});
