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
        'scheduled_date' => '2026-06-01',
        'scheduled_time' => '09:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    getJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules")->assertOk();
});

test('public can view a single park activity schedule without auth', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $schedule = $activity->schedules()->create([
        'scheduled_date' => '2026-06-01',
        'scheduled_time' => '09:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    getJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")->assertOk();
});

test('unauthenticated user cannot create a park activity schedule', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);

    $this->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
        'scheduled_date' => '2026-06-01',
        'scheduled_time' => '09:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ])->assertUnauthorized();
});

test('customer cannot create a park activity schedule', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
            'scheduled_date' => '2026-06-01',
            'scheduled_time' => '09:00:00',
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
            'scheduled_date' => '2026-06-01',
            'scheduled_time' => '09:00:00',
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
            'scheduled_date' => '2026-06-01',
            'scheduled_time' => '10:00:00',
            'status' => ParkActivitySchedule::STATUS_COMPLETED,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', ParkActivitySchedule::STATUS_COMPLETED);
});

test('park-manager can update a park activity schedule', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $schedule = $activity->schedules()->create([
        'scheduled_date' => '2026-06-01',
        'scheduled_time' => '09:00:00',
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
        'scheduled_date' => '2026-06-01',
        'scheduled_time' => '09:00:00',
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
        'scheduled_date' => '2026-06-01',
        'scheduled_time' => '09:00:00',
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertNoContent();
});
