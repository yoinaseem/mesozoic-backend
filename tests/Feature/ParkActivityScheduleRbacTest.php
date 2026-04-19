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
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertNoContent();
});

test('end_time is derived from activity duration when column is null', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'duration' => 45,
        'is_all_day' => false,
    ]);
    $schedule = $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '09:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    getJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertOk()
        ->assertJsonPath('data.start_time', '09:00:00')
        ->assertJsonPath('data.end_time', '09:45:00')
        ->assertJsonPath('data.end_time_source', 'derived');
});

test('derived end_time wraps past midnight', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'duration' => 300,
        'is_all_day' => false,
    ]);
    $schedule = $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '22:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    getJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertOk()
        ->assertJsonPath('data.end_time', '03:00:00');
});

test('explicit end_time overrides derivation', function () {
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

test('end_time is null when activity has no duration and none is supplied', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'duration' => null,
        'is_all_day' => true,
    ]);
    $schedule = $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '09:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    getJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertOk()
        ->assertJsonPath('data.end_time', null)
        ->assertJsonPath('data.end_time_source', null);
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
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
            'date' => '2026-06-01',
            'start_time' => '09:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start_time');
});
