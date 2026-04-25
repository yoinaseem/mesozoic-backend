<?php

use App\Models\ParkActivity;
use App\Models\ParkActivitySchedule;
use App\Models\ThemePark;
use App\Models\User;

use function Pest\Laravel\getJson;

test('public can list park activity schedules without auth', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    getJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules")->assertOk();
});

test('public can view a single park activity schedule without auth', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $schedule = $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    getJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")->assertOk();
});

test('unauthenticated user cannot create a park activity schedule', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);

    $this->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
        'date' => '2026-06-01',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ])->assertUnauthorized();
});

test('customer cannot create a park activity schedule', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
            'date' => '2026-06-01',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => ParkActivitySchedule::STATUS_SCHEDULED,
        ])
        ->assertForbidden();
});

test('park-manager can create a park activity schedule', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
            'date' => '2026-06-01',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => ParkActivitySchedule::STATUS_SCHEDULED,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', ParkActivitySchedule::STATUS_SCHEDULED);
});

test('superadmin can create a park activity schedule', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
            'date' => '2026-06-01',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => ParkActivitySchedule::STATUS_COMPLETED,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', ParkActivitySchedule::STATUS_COMPLETED);
});

test('park-manager can update a park activity schedule', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $schedule = $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}", [
            'status' => ParkActivitySchedule::STATUS_CANCELLED,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', ParkActivitySchedule::STATUS_CANCELLED);
});

test('park-manager cannot delete a park activity schedule', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $schedule = $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->deleteJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertForbidden();
});

test('superadmin can delete a park activity schedule', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $schedule = $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertNoContent();
});

test('end_time is read directly from the column (Model B canonical)', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'duration' => 45,
        'is_all_day' => false,
    ]);
    $schedule = $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '09:00:00',
        'end_time' => '09:45:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    getJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertOk()
        ->assertJsonPath('data.start_time', '09:00:00')
        ->assertJsonPath('data.end_time', '09:45:00')
        ->assertJsonPath('data.end_time_source', 'explicit');
});

test('overnight end_time stored verbatim and emitted as-is', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'duration' => 300,
        'is_all_day' => false,
    ]);
    $schedule = $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '22:00:00',
        'end_time' => '03:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    getJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertOk()
        ->assertJsonPath('data.end_time', '03:00:00');
});

test('explicit end_time is stored as posted', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'duration' => 45,
        'is_all_day' => false,
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
            'date' => '2026-06-01',
            'start_time' => '09:00:00',
            'end_time' => '11:30:00',
        ])
        ->assertCreated()
        ->assertJsonPath('data.end_time', '11:30:00')
        ->assertJsonPath('data.end_time_source', 'explicit');
});

test('create rejects missing end_time', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
            'date' => '2026-06-01',
            'start_time' => '09:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_time');
});

test('create rejects end_time equal to start_time', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
            'date' => '2026-06-01',
            'start_time' => '09:00:00',
            'end_time' => '09:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_time');
});

test('create allows overnight end_time earlier than start_time', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
            'date' => '2026-10-31',
            'start_time' => '22:00:00',
            'end_time' => '02:00:00',
        ])
        ->assertCreated()
        ->assertJsonPath('data.end_time', '02:00:00');
});

test('duplicate date+start_time is rejected', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
            'date' => '2026-06-01',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start_time');
});

test('superadmin can archive a schedule (soft-delete)', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $schedule = $activity->schedules()->create([
        'date' => '2026-07-01',
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertNoContent();

    expect($schedule->fresh()->trashed())->toBeTrue();
});

test('archive is blocked when a schedule has upcoming confirmed bookings', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $schedule = $activity->schedules()->create([
        'date' => now()->addDays(5)->toDateString(),
        'start_time' => '12:00:00',
        'end_time' => '13:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    $customer = User::factory()->customer()->create();
    $reservation = \App\Models\Reservation::create(['user_id' => $customer->id]);
    \App\Models\ParkActivityBooking::create([
        'reservation_id'            => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests'                    => 1,
        'status'                    => 'confirmed',
        'price_per_guest'           => $activity->price,
        'total_price'               => (float) $activity->price,
    ]);

    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertStatus(409)
        ->assertJsonPath('blocking_bookings', 1);

    expect($schedule->fresh()->trashed())->toBeFalse();
});

test('schedule slot can be reused after the original is archived', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $old = $activity->schedules()->create([
        'date' => '2026-08-15',
        'start_time' => '14:00:00',
        'end_time' => '15:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);
    $old->delete();

    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
            'date' => '2026-08-15',
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
        ])
        ->assertCreated();
});
