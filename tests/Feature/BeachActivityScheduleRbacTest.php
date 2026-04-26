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
        'end_time' => '10:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);

    getJson("/api/beach-activities/{$activity->id}/schedules")->assertOk();
});

test('public can view a single schedule without auth', function () {
    $activity = BeachActivity::factory()->create();
    $schedule = $activity->schedules()->create([
        'activity_date' => '2026-05-01',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);

    getJson("/api/beach-activities/{$activity->id}/schedules/{$schedule->id}")->assertOk();
});

test('unauthenticated user cannot create a schedule', function () {
    $activity = BeachActivity::factory()->create();

    $this->postJson("/api/beach-activities/{$activity->id}/schedules", [
        'activity_date' => '2026-05-01',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
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
            'end_time' => '10:00:00',
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
            'end_time' => '10:00:00',
            'status' => BeachActivitySchedule::STATUS_PENDING,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', BeachActivitySchedule::STATUS_PENDING)
        ->assertJsonPath('data.end_time', '10:00:00');
});

test('superadmin can create a schedule', function () {
    $activity = BeachActivity::factory()->create();
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->postJson("/api/beach-activities/{$activity->id}/schedules", [
            'activity_date' => '2026-05-01',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
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
        'end_time' => '10:00:00',
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
        'end_time' => '10:00:00',
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
        'end_time' => '10:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/beach-activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertNoContent();
});

// DESD-97 — past-date guard

test('schedule on a past date is rejected on create', function () {
    $activity = BeachActivity::factory()->create();
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/beach-activities/{$activity->id}/schedules", [
            'activity_date' => now()->subDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('activity_date');
});

test('moving a schedule to a past activity_date via update is rejected', function () {
    $activity = BeachActivity::factory()->create();
    $schedule = $activity->schedules()->create([
        'activity_date' => now()->addDays(5)->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/beach-activities/{$activity->id}/schedules/{$schedule->id}", [
            'activity_date' => now()->subDays(2)->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('activity_date');
});

// DESD-97 hotfix: past-date guard must compare parsed dates, not raw strings.
// `12/31/2099` is far in the future but lexicographically sorts before today's
// ISO date — the original raw-string `<` comparison incorrectly rejected it
// as past. The validator-driven `after_or_equal:today` parses both sides
// through Carbon so the comparison is correct.
test('moving a schedule to a future non-ISO activity_date is accepted (regression)', function () {
    $activity = BeachActivity::factory()->create();
    $schedule = $activity->schedules()->create([
        'activity_date' => now()->addDays(5)->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/beach-activities/{$activity->id}/schedules/{$schedule->id}", [
            'activity_date' => '12/31/2099',
        ])
        ->assertOk()
        ->assertJsonPath('data.activity_date', '2099-12-31');
});

test('status-only update on a past schedule succeeds', function () {
    $activity = BeachActivity::factory()->create();
    $schedule = $activity->schedules()->create([
        'activity_date' => now()->subDays(5)->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'status' => BeachActivitySchedule::STATUS_CONFIRMED,
    ]);
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/beach-activities/{$activity->id}/schedules/{$schedule->id}", [
            'status' => BeachActivitySchedule::STATUS_CANCELLED,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', BeachActivitySchedule::STATUS_CANCELLED);
});

// DESD-97 — slot reuse after cancellation (partial unique excludes cancelled)

test('schedule slot can be reused after the original is cancelled', function () {
    $activity = BeachActivity::factory()->create();
    $futureDate = now()->addDays(7)->toDateString();
    $original = $activity->schedules()->create([
        'activity_date' => $futureDate,
        'start_time' => '14:00:00',
        'end_time' => '15:00:00',
        'status' => BeachActivitySchedule::STATUS_CANCELLED,
    ]);

    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/beach-activities/{$activity->id}/schedules", [
            'activity_date' => $futureDate,
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
        ])
        ->assertCreated();

    expect($original->fresh()->status)->toBe(BeachActivitySchedule::STATUS_CANCELLED);
});

test('duplicate live slot is rejected with 422 errors.start_time', function () {
    $activity = BeachActivity::factory()->create();
    $futureDate = now()->addDays(7)->toDateString();
    $activity->schedules()->create([
        'activity_date' => $futureDate,
        'start_time' => '14:00:00',
        'end_time' => '15:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);

    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/beach-activities/{$activity->id}/schedules", [
            'activity_date' => $futureDate,
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start_time');
});

// DESD-97 — Model B canonical end_time

test('create rejects missing end_time', function () {
    $activity = BeachActivity::factory()->create();
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/beach-activities/{$activity->id}/schedules", [
            'activity_date' => now()->addDays(3)->toDateString(),
            'start_time' => '09:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_time');
});

test('create rejects end_time equal to start_time', function () {
    $activity = BeachActivity::factory()->create();
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/beach-activities/{$activity->id}/schedules", [
            'activity_date' => now()->addDays(3)->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_time');
});

test('overnight end_time stored verbatim and emitted as-is', function () {
    $activity = BeachActivity::factory()->create();
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/beach-activities/{$activity->id}/schedules", [
            'activity_date' => now()->addDays(3)->toDateString(),
            'start_time' => '22:00:00',
            'end_time' => '02:00:00',
        ])
        ->assertCreated()
        ->assertJsonPath('data.start_time', '22:00:00')
        ->assertJsonPath('data.end_time', '02:00:00');
});

test('end_time is emitted by the schedule resource', function () {
    $activity = BeachActivity::factory()->create();
    $schedule = $activity->schedules()->create([
        'activity_date' => '2026-05-01',
        'start_time' => '09:00:00',
        'end_time' => '11:30:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);

    getJson("/api/beach-activities/{$activity->id}/schedules/{$schedule->id}")
        ->assertOk()
        ->assertJsonPath('data.start_time', '09:00:00')
        ->assertJsonPath('data.end_time', '11:30:00');
});

// DESD-97 — overlap detection (BeachScheduleReconciler)

test('overlapping schedule on create is rejected with 422 errors.start_time', function () {
    $activity = BeachActivity::factory()->create();
    $futureDate = now()->addDays(7)->toDateString();
    $activity->schedules()->create([
        'activity_date' => $futureDate,
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/beach-activities/{$activity->id}/schedules", [
            'activity_date' => $futureDate,
            'start_time' => '10:30:00',
            'end_time' => '11:30:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start_time');
});

test('non-overlapping back-to-back schedules are accepted (half-open intervals)', function () {
    $activity = BeachActivity::factory()->create();
    $futureDate = now()->addDays(7)->toDateString();
    $activity->schedules()->create([
        'activity_date' => $futureDate,
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/beach-activities/{$activity->id}/schedules", [
            'activity_date' => $futureDate,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ])
        ->assertCreated();
});

test('overlap on update is rejected; ignoring the schedule itself works', function () {
    $activity = BeachActivity::factory()->create();
    $futureDate = now()->addDays(7)->toDateString();
    $activity->schedules()->create([
        'activity_date' => $futureDate,
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);
    $target = $activity->schedules()->create([
        'activity_date' => $futureDate,
        'start_time' => '11:00:00',
        'end_time' => '12:00:00',
        'status' => BeachActivitySchedule::STATUS_PENDING,
    ]);
    $manager = User::factory()->beachManager()->create();

    // Move target to 09:30–10:30 — overlaps the 09:00–10:00 row.
    $this->actingAs($manager)
        ->patchJson("/api/beach-activities/{$activity->id}/schedules/{$target->id}", [
            'start_time' => '09:30:00',
            'end_time' => '10:30:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start_time');

    // Trim the target's own window without touching another row — should pass
    // (ignoreId excludes self from the overlap query).
    $this->actingAs($manager)
        ->patchJson("/api/beach-activities/{$activity->id}/schedules/{$target->id}", [
            'end_time' => '11:30:00',
        ])
        ->assertOk()
        ->assertJsonPath('data.end_time', '11:30:00');
});
