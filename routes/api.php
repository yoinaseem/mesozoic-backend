<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\FerryController;
use App\Http\Controllers\FerryScheduleController;
use App\Http\Controllers\BeachActivityController;
use App\Http\Controllers\BeachActivityScheduleController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\ParkActivityController;
use App\Http\Controllers\ParkActivityScheduleController;
use App\Http\Controllers\ParkOpeningHourController;
use App\Http\Controllers\ThemeParkController;
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

// Public reads — beach activities and schedules are browsable without auth.
Route::apiResource('beach-activities', BeachActivityController::class)->only(['index', 'show']);
Route::scopeBindings()->prefix('beach-activities/{beach_activity}')->group(function () {
    Route::apiResource('schedules', BeachActivityScheduleController::class)->only(['index', 'show']);
});

// Public reads — theme parks, opening hours, activities, and schedules.
Route::apiResource('theme-parks', ThemeParkController::class)->only(['index', 'show']);
Route::scopeBindings()->prefix('theme-parks/{theme_park}')->group(function () {
    Route::apiResource('opening-hours', ParkOpeningHourController::class)->only(['index', 'show']);
    Route::apiResource('activities', ParkActivityController::class)
        ->parameters(['activities' => 'park_activity'])
        ->only(['index', 'show']);
});
Route::scopeBindings()->prefix('theme-parks/{theme_park}/activities/{park_activity}')->group(function () {
    Route::apiResource('schedules', ParkActivityScheduleController::class)
        ->only(['index', 'show'])
        ->names('park-activity-schedules');
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

    // Beach activity mutations
    Route::post('/beach-activities', [BeachActivityController::class, 'store'])
        ->middleware('permission:beach.create');
    Route::match(['put', 'patch'], '/beach-activities/{beach_activity}', [BeachActivityController::class, 'update'])
        ->middleware('permission:beach.update');
    Route::delete('/beach-activities/{beach_activity}', [BeachActivityController::class, 'destroy'])
        ->middleware('permission:beach.delete');

    // Nested schedule mutations
    Route::scopeBindings()->prefix('beach-activities/{beach_activity}')->group(function () {
        Route::apiResource('schedules', BeachActivityScheduleController::class)->except(['index', 'show'])
            ->middleware('permission:beach.create|beach.update|beach.delete');
    });

    // Theme park mutations (flat + nested; policies + middleware use park.*).
    Route::post('/theme-parks', [ThemeParkController::class, 'store'])
        ->middleware('permission:park.create');
    Route::match(['put', 'patch'], '/theme-parks/{theme_park}', [ThemeParkController::class, 'update'])
        ->middleware('permission:park.update');
    Route::delete('/theme-parks/{theme_park}', [ThemeParkController::class, 'destroy'])
        ->middleware('permission:park.delete');

    Route::scopeBindings()->prefix('theme-parks/{theme_park}')->group(function () {
        Route::post('/opening-hours', [ParkOpeningHourController::class, 'store'])
            ->middleware('permission:park.create');
        Route::match(['put', 'patch'], '/opening-hours/{opening_hour}', [ParkOpeningHourController::class, 'update'])
            ->middleware('permission:park.update');
        Route::delete('/opening-hours/{opening_hour}', [ParkOpeningHourController::class, 'destroy'])
            ->middleware('permission:park.delete');

        Route::post('/activities', [ParkActivityController::class, 'store'])
            ->middleware('permission:park.create');
        Route::match(['put', 'patch'], '/activities/{park_activity}', [ParkActivityController::class, 'update'])
            ->middleware('permission:park.update');
        Route::delete('/activities/{park_activity}', [ParkActivityController::class, 'destroy'])
            ->middleware('permission:park.delete');
    });

    Route::scopeBindings()->prefix('theme-parks/{theme_park}/activities/{park_activity}')->group(function () {
        Route::apiResource('schedules', ParkActivityScheduleController::class)
            ->except(['index', 'show'])
            ->middleware('permission:park.create|park.update|park.delete')
            ->names('park-activity-schedules');
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
