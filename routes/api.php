<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\FerryController;
use App\Http\Controllers\FerryScheduleController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomTypeController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Public reads — hotels, room-types, and rooms are browsable without auth.
Route::apiResource('hotels', HotelController::class)->only(['index', 'show']);
Route::scopeBindings()->prefix('hotels/{hotel}')->group(function () {
    Route::apiResource('room-types', RoomTypeController::class)->only(['index', 'show']);
    Route::apiResource('rooms',      RoomController::class)->only(['index', 'show']);
});

// Auth entry points.
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login',    [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Users — UserPolicy gates every verb (superadmin-only + self-access).
    Route::apiResource('users', UserController::class);

    // Hotel mutations — each verb gets its specific permission on the route,
    // then HotelPolicy re-checks on a per-hotel basis for update (scope).
    Route::post('/hotels', [HotelController::class, 'store'])
        ->middleware('permission:hotels.create');
    Route::match(['put', 'patch'], '/hotels/{hotel}', [HotelController::class, 'update'])
        ->middleware('permission:hotels.update');
    Route::delete('/hotels/{hotel}', [HotelController::class, 'destroy'])
        ->middleware('permission:hotels.delete');

    // Nested room-type and room mutations — pipe = OR (any of the three
    // permissions lets the request through the middleware); the per-verb
    // check happens inside the Policy.
    Route::scopeBindings()->prefix('hotels/{hotel}')->group(function () {
        Route::apiResource('room-types', RoomTypeController::class)->except(['index', 'show'])
            ->middleware('permission:room-types.create|room-types.update|room-types.delete');
        Route::apiResource('rooms', RoomController::class)->except(['index', 'show'])
            ->middleware('permission:rooms.create|rooms.update|rooms.delete');
    });
});

// Ferries & schedules – read-only public, mutations protected (same pattern as hotels)
Route::get('ferries/{ferry}/schedules', [FerryScheduleController::class, 'indexForFerry']);
Route::apiResource('ferries', FerryController::class)->only(['index', 'show']);
Route::apiResource('ferry-schedules', FerryScheduleController::class)->only(['index', 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('ferries', FerryController::class)->except(['index', 'show']);
    Route::apiResource('ferry-schedules', FerryScheduleController::class)->except(['index', 'show']);
});
