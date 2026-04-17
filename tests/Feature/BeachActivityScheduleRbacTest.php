<?php

use App\Models\BeachActivity;
use App\Models\BeachActivitySchedule;
use App\Models\User;

use function Pest\Laravel\getJson;

test('public can list schedules without auth', function () {
    $activity = BeachActivity::factory()->create();
    $activity->schedules()->create([
        'activity_date' => '2026-05-01',
        'start_time' => '09:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);

    getJson("/api/beach-activities/{$activity->id}/schedules")->assertOk();
});

test('public can view a single schedule without auth', function () {
    $activity = BeachActivity::factory()->create();
    $schedule = $activity->schedules()->create([
        'activity_date' => '2026-05-01',
        'start_time' => '09:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);

    getJson("/api/beach-activities/{$activity->id}/schedules/{$schedule->id}")->assertOk();
});

test('unauthenticated user cannot create a schedule', function () {
    $activity = BeachActivity::factory()->create();

    $this->postJson("/api/beach-activities/{$activity->id}/schedules", [
        'activity_date' => '2026-05-01',
        'start_time' => '09:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ])->assertUnauthorized();
});

test('customer cannot create a schedule', function () {
    $activity = BeachActivity::factory()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson("/api/beach-activities/{$activity->id}/schedules", [
            'activity_date' => '2026-05-01',
            'start_time' => '09:00:00',
            'status' => BeachActivitySchedule::STATUS_PENDING,
        ])
        ->assertForbidden();
});

test('beach-manager can create a schedule', function () {
    $activity = BeachActivity::factory()->create();
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/beach-activities/{$activity->id}/schedules", [
            'activity_date' => '2026-05-01',
            'start_time' => '09:00:00',
            'status' => BeachActivitySchedule::STATUS_PENDING,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', BeachActivitySchedule::STATUS_PENDING);
});

test('superadmin can create a schedule', function () {
    $activity = BeachActivity::factory()->create();
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->postJson("/api/beach-activities/{$activity->id}/schedules", [
            'activity_date' => '2026-05-01',
            'start_time' => '10:00:00',
            'status' => BeachActivitySchedule::STATUS_CONFIRMED,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', BeachActivitySchedule::STATUS_CONFIRMED);
});

test('beach-manager can update a schedule', function () {
    $activity = BeachActivity::factory()->create();
    $schedule = $activity->schedules()->create([
        'activity_date' => '2026-05-01',
        'start_time' => '09:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/beach-activities/{$activity->id}/schedules/{$schedule->id}", [
            'status' => BeachActivitySchedule::STATUS_CONFIRMED,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', BeachActivitySchedule::STATUS_CONFIRMED);
});

test('beach-manager cannot delete a schedule', function () {
    $activity = BeachActivity::factory()->create();
    $schedule = $activity->schedules()->create([
        'activity_date' => '2026-05-01',
        'start_time' => '09:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->deleteJson("/api/beach-activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertForbidden();
});

test('superadmin can delete a schedule', function () {
    $activity = BeachActivity::factory()->create();
    $schedule = $activity->schedules()->create([
        'activity_date' => '2026-05-01',
        'start_time' => '09:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/beach-activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertNoContent();
});
