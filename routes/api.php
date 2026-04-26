<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BeachActivityController;
use App\Http\Controllers\BeachActivityScheduleController;
use App\Http\Controllers\HotelAvailabilityController;
use App\Http\Controllers\BeachBookingController;
use App\Http\Controllers\FerryBookingController;
use App\Http\Controllers\FerryController;
use App\Http\Controllers\FerryScheduleController;
use App\Http\Controllers\FerryTypeController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\ParkActivityBookingController;
use App\Http\Controllers\ParkActivityController;
use App\Http\Controllers\ParkActivityScheduleController;
use App\Http\Controllers\ParkBookingController;
use App\Http\Controllers\ParkEffectiveHoursController;
use App\Http\Controllers\ParkHourOverrideController;
use App\Http\Controllers\ParkOpeningHourController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RoomBookingController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomTypeController;
use App\Http\Controllers\ThemeParkController;
use App\Http\Controllers\UserAccessController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Public reads — hotels, room-types, and rooms are browsable without auth.
Route::apiResource('hotels', HotelController::class)->only(['index', 'show']);
Route::scopeBindings()->prefix('hotels/{hotel}')->group(function () {
    Route::apiResource('room-types', RoomTypeController::class)->only(['index', 'show']);
    Route::apiResource('rooms', RoomController::class)->only(['index', 'show']);
    Route::get('availability', HotelAvailabilityController::class);
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
    Route::apiResource('hour-overrides', ParkHourOverrideController::class)->only(['index', 'show']);
    Route::get('effective-hours', ParkEffectiveHoursController::class);
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
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/me/hotels', [AuthController::class, 'meHotels']);

    // Users — UserPolicy gates every verb (superadmin-only + self-access).
    Route::apiResource('users', UserController::class);
    Route::post('users/{user}/restore', [UserController::class, 'restore'])
        ->withTrashed();

    // RBAC management — list catalogue, CRUD on roles, sync user roles +
    // hotel-manager pivot, sync direct user permissions. All gated by
    // `roles.manage` at the middleware layer; RolePolicy / UserPolicy
    // re-check inside the controllers.
    Route::get('/permissions', [PermissionController::class, 'index'])
        ->middleware('permission:roles.manage');
    Route::apiResource('roles', RoleController::class)
        ->middleware('permission:roles.manage');
    Route::put('users/{user}/roles', [UserAccessController::class, 'roles'])
        ->middleware('permission:roles.manage');
    Route::put('users/{user}/permissions', [UserAccessController::class, 'permissions'])
        ->middleware('permission:roles.manage');

    // Hotel mutations — each verb gets its specific permission on the route,
    // then HotelPolicy re-checks on a per-hotel basis for update (scope).
    Route::post('/hotels', [HotelController::class, 'store'])
        ->middleware('permission:hotels.create');
    Route::match(['put', 'patch'], '/hotels/{hotel}', [HotelController::class, 'update'])
        ->middleware('permission:hotels.update');
    Route::delete('/hotels/{hotel}', [HotelController::class, 'destroy'])
        ->middleware('permission:hotels.delete');
    // Restore an archived hotel — withTrashed() so the route-model binding
    // resolves the soft-deleted row. Same permission + policy as delete.
    Route::post('/hotels/{hotel}/restore', [HotelController::class, 'restore'])
        ->withTrashed()
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

    // Restore endpoints for archived room-types / rooms. withTrashed() is
    // applied on both bindings so archived parents (and their children)
    // still resolve.
    Route::scopeBindings()->prefix('hotels/{hotel}')->group(function () {
        Route::post('room-types/{roomType}/restore', [RoomTypeController::class, 'restore'])
            ->withTrashed()
            ->middleware('permission:room-types.delete');
        Route::post('rooms/{room}/restore', [RoomController::class, 'restore'])
            ->withTrashed()
            ->middleware('permission:rooms.delete');
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
    // Restore archived theme park — withTrashed binding resolves the soft-
    // deleted row. Same permission + policy as delete.
    Route::post('/theme-parks/{theme_park}/restore', [ThemeParkController::class, 'restore'])
        ->withTrashed()
        ->middleware('permission:park.delete');

    Route::scopeBindings()->prefix('theme-parks/{theme_park}')->group(function () {
        Route::post('/opening-hours', [ParkOpeningHourController::class, 'store'])
            ->middleware('permission:park.create');
        Route::match(['put', 'patch'], '/opening-hours/{opening_hour}', [ParkOpeningHourController::class, 'update'])
            ->middleware('permission:park.update');
        Route::delete('/opening-hours/{opening_hour}', [ParkOpeningHourController::class, 'destroy'])
            ->middleware('permission:park.delete');

        Route::post('/hour-overrides', [ParkHourOverrideController::class, 'store'])
            ->middleware('permission:park.create');
        Route::match(['put', 'patch'], '/hour-overrides/{hour_override}', [ParkHourOverrideController::class, 'update'])
            ->middleware('permission:park.update');
        Route::delete('/hour-overrides/{hour_override}', [ParkHourOverrideController::class, 'destroy'])
            ->middleware('permission:park.delete');

        Route::post('/activities', [ParkActivityController::class, 'store'])
            ->middleware('permission:park.create');
        Route::match(['put', 'patch'], '/activities/{park_activity}', [ParkActivityController::class, 'update'])
            ->middleware('permission:park.update');
        Route::delete('/activities/{park_activity}', [ParkActivityController::class, 'destroy'])
            ->middleware('permission:park.delete');
    });

    // Restore routes for nested park activities + schedules — separated so
    // withTrashed applies to both route params.
    Route::scopeBindings()->prefix('theme-parks/{theme_park}')->group(function () {
        Route::post('/activities/{park_activity}/restore', [ParkActivityController::class, 'restore'])
            ->withTrashed()
            ->middleware('permission:park.delete');
    });

    Route::scopeBindings()->prefix('theme-parks/{theme_park}/activities/{park_activity}')->group(function () {
        Route::apiResource('schedules', ParkActivityScheduleController::class)
            ->except(['index', 'show'])
            ->middleware('permission:park.create|park.update|park.delete')
            ->names('park-activity-schedules');

        Route::post('/schedules/{schedule}/restore', [ParkActivityScheduleController::class, 'restore'])
            ->withTrashed()
            ->middleware('permission:park.create|park.update|park.delete')
            ->name('park-activity-schedules.restore');
    });

    // Reservations — read-only. Created implicitly via /room-bookings.
    Route::get('/reservations', [ReservationController::class, 'index'])
        ->middleware('permission:bookings.view');
    Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])
        ->middleware('permission:bookings.view');

    // RoomBookings — per-verb perms (customer lacks bookings.update, so
    // apiResource+pipe-OR would leak PATCH to customers at the middleware layer).
    Route::get('/room-bookings', [RoomBookingController::class, 'index'])
        ->middleware('permission:bookings.view');
    Route::get('/room-bookings/{room_booking}', [RoomBookingController::class, 'show'])
        ->middleware('permission:bookings.view');
    Route::post('/room-bookings', [RoomBookingController::class, 'store'])
        ->middleware('permission:bookings.create');
    Route::match(['put', 'patch'], '/room-bookings/{room_booking}', [RoomBookingController::class, 'update'])
        ->middleware('permission:bookings.update');
    Route::delete('/room-bookings/{room_booking}', [RoomBookingController::class, 'destroy'])
        ->middleware('permission:bookings.cancel');

    // ParkBookings — day-pass tied to a confirmed room booking. Same
    // per-verb split as /room-bookings so customers (who lack bookings.update)
    // can POST + DELETE but not PATCH.
    Route::get('/park-bookings', [ParkBookingController::class, 'index'])
        ->middleware('permission:bookings.view');
    Route::get('/park-bookings/{park_booking}', [ParkBookingController::class, 'show'])
        ->middleware('permission:bookings.view');
    Route::post('/park-bookings', [ParkBookingController::class, 'store'])
        ->middleware('permission:bookings.create');
    Route::match(['put', 'patch'], '/park-bookings/{park_booking}', [ParkBookingController::class, 'update'])
        ->middleware('permission:bookings.update');
    Route::delete('/park-bookings/{park_booking}', [ParkBookingController::class, 'destroy'])
        ->middleware('permission:bookings.cancel');

    // BeachBookings — session ticket tied to a BeachActivitySchedule. Same
    // per-verb permission split as /park-bookings; cancellation is staff-only
    // (BeachBookingPolicy::delete requires beach-manager) so customers can
    // POST but not DELETE.
    Route::get('/beach-bookings', [BeachBookingController::class, 'index'])
        ->middleware('permission:bookings.view');
    Route::get('/beach-bookings/{beach_booking}', [BeachBookingController::class, 'show'])
        ->middleware('permission:bookings.view');
    Route::post('/beach-bookings', [BeachBookingController::class, 'store'])
        ->middleware('permission:bookings.create');
    Route::match(['put', 'patch'], '/beach-bookings/{beach_booking}', [BeachBookingController::class, 'update'])
        ->middleware('permission:bookings.update');
    Route::delete('/beach-bookings/{beach_booking}', [BeachBookingController::class, 'destroy'])
        ->middleware('permission:bookings.cancel');

    // ParkActivityBookings — session ticket tied to a ParkActivitySchedule.
    // Requires a confirmed ParkBooking (day-pass) on the same park+date via
    // ParkActivityBookingController::assertHoldsDayPass. Cancellation is
    // staff-only (policy requires park-manager); customers POST but not DELETE.
    Route::get('/park-activity-bookings', [ParkActivityBookingController::class, 'index'])
        ->middleware('permission:bookings.view');
    Route::get('/park-activity-bookings/{park_activity_booking}', [ParkActivityBookingController::class, 'show'])
        ->middleware('permission:bookings.view');
    Route::post('/park-activity-bookings', [ParkActivityBookingController::class, 'store'])
        ->middleware('permission:bookings.create');
    Route::match(['put', 'patch'], '/park-activity-bookings/{park_activity_booking}', [ParkActivityBookingController::class, 'update'])
        ->middleware('permission:bookings.update');
    Route::delete('/park-activity-bookings/{park_activity_booking}', [ParkActivityBookingController::class, 'destroy'])
        ->middleware('permission:bookings.cancel');

    // FerryBookings — trip ticket tied to a FerrySchedule (specific departure).
    // Uses the *inclusive* seat-pool window so arrival-day and departure-day
    // ferries are both bookable (see Reservation::ferrySeatPoolOn).
    // Cancellation is staff-only (policy requires ferry-manager).
    Route::get('/ferry-bookings', [FerryBookingController::class, 'index'])
        ->middleware('permission:bookings.view');
    Route::get('/ferry-bookings/{ferry_booking}', [FerryBookingController::class, 'show'])
        ->middleware('permission:bookings.view');
    Route::post('/ferry-bookings', [FerryBookingController::class, 'store'])
        ->middleware('permission:bookings.create');
    Route::match(['put', 'patch'], '/ferry-bookings/{ferry_booking}', [FerryBookingController::class, 'update'])
        ->middleware('permission:bookings.update');
    Route::delete('/ferry-bookings/{ferry_booking}', [FerryBookingController::class, 'destroy'])
        ->middleware('permission:bookings.cancel');
});

// Ferry types & ferries & schedules — read-only public, mutations gated by ferry.* perms.
// FerryType is the catalogue (price + capacity); Ferry is a physical vessel that inherits both.
Route::apiResource('ferry-types', FerryTypeController::class)->only(['index', 'show']);
Route::get('ferries/{ferry}/schedules', [FerryScheduleController::class, 'indexForFerry']);
Route::apiResource('ferries', FerryController::class)->only(['index', 'show']);
Route::apiResource('ferry-schedules', FerryScheduleController::class)->only(['index', 'show']);

Route::middleware('auth:sanctum')->group(function () {
    // FerryType mutations — pipe-OR middleware; per-verb check is in FerryTypePolicy.
    Route::apiResource('ferry-types', FerryTypeController::class)->except(['index', 'show'])
        ->middleware('permission:ferry.create|ferry.update|ferry.delete');

    // Ferry (vessel) mutations — same per-verb gating as ferry-types.
    Route::apiResource('ferries', FerryController::class)->except(['index', 'show'])
        ->middleware('permission:ferry.create|ferry.update|ferry.delete');

    // FerrySchedules — per-verb permission split (no FerrySchedulePolicy exists, so
    // pipe-OR would let ferry-manager DELETE since they hold ferry.create/.update).
    Route::post('/ferry-schedules', [FerryScheduleController::class, 'store'])
        ->middleware('permission:ferry.create');
    Route::match(['put', 'patch'], '/ferry-schedules/{ferry_schedule}', [FerryScheduleController::class, 'update'])
        ->middleware('permission:ferry.update');
    Route::delete('/ferry-schedules/{ferry_schedule}', [FerryScheduleController::class, 'destroy'])
        ->middleware('permission:ferry.delete');
});
