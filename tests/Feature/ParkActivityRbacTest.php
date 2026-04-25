<?php

use App\Models\ParkActivity;
use App\Models\ThemePark;
use App\Models\User;

use function Pest\Laravel\getJson;

test('public can list park activities for a theme park without auth', function () {
    $park = ThemePark::factory()->create();
    ParkActivity::factory()->count(2)->create(['park_id' => $park->id]);

    getJson("/api/theme-parks/{$park->id}/activities")->assertOk();
});

test('public can view a single park activity without auth', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);

    getJson("/api/theme-parks/{$park->id}/activities/{$activity->id}")->assertOk();
});

test('unauthenticated user cannot create a park activity', function () {
    $park = ThemePark::factory()->create();

    $this->postJson("/api/theme-parks/{$park->id}/activities", [
        'name' => 'Coaster',
        'description' => 'Fast',
        'price' => 10,
        'duration' => 45,
        'max_capacity' => 24,
    ])->assertUnauthorized();
});

test('customer cannot create a park activity', function () {
    $park = ThemePark::factory()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson("/api/theme-parks/{$park->id}/activities", [
            'name' => 'Coaster',
            'description' => 'Fast',
            'price' => 10,
            'duration' => 45,
            'max_capacity' => 24,
        ])
        ->assertForbidden();
});

test('park-manager can create a park activity', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities", [
            'name' => 'Coaster',
            'description' => 'Fast',
            'price' => 10,
            'duration' => 45,
            'max_capacity' => 24,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Coaster');
});

test('superadmin can create a park activity', function () {
    $park = ThemePark::factory()->create();
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->postJson("/api/theme-parks/{$park->id}/activities", [
            'name' => 'River Ride',
            'description' => 'Wet',
            'price' => 5,
            'duration' => 30,
            'max_capacity' => 12,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'River Ride');
});

test('park-manager can update a park activity', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->putJson("/api/theme-parks/{$park->id}/activities/{$activity->id}", ['name' => 'Updated Ride'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Ride');
});

test('customer cannot update a park activity', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->putJson("/api/theme-parks/{$park->id}/activities/{$activity->id}", ['name' => 'No Access'])
        ->assertForbidden();
});

test('superadmin can update any park activity', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->putJson("/api/theme-parks/{$park->id}/activities/{$activity->id}", ['name' => 'Admin Update'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Admin Update');
});

test('park-manager cannot delete a park activity', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->deleteJson("/api/theme-parks/{$park->id}/activities/{$activity->id}")
        ->assertForbidden();
});

test('superadmin can delete a park activity', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/activities/{$activity->id}")
        ->assertNoContent();
});

test('park-manager can create an all-day activity without duration', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities", [
            'name' => 'Park Pass',
            'description' => 'Open all day',
            'price' => 50,
            'max_capacity' => 1000,
            'is_all_day' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_all_day', true)
        ->assertJsonPath('data.duration', null);
});

test('creating an all-day activity nulls any supplied duration', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities", [
            'name' => 'Park Pass',
            'description' => 'Open all day',
            'price' => 50,
            'max_capacity' => 1000,
            'duration' => 60,
            'is_all_day' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.duration', null);
});

test('creating a non-all-day activity without duration fails validation', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities", [
            'name' => 'Coaster',
            'description' => 'Fast',
            'price' => 10,
            'max_capacity' => 24,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('duration');
});

test('toggling an activity to all-day clears its duration', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'duration' => 45,
        'is_all_day' => false,
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/activities/{$activity->id}", [
            'is_all_day' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_all_day', true)
        ->assertJsonPath('data.duration', null);
});

test('toggling an all-day activity to non-all-day without duration fails validation', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'duration' => null,
        'is_all_day' => true,
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/activities/{$activity->id}", [
            'is_all_day' => false,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('duration');
});

test('toggling an all-day activity to non-all-day succeeds when duration is provided', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'duration' => null,
        'is_all_day' => true,
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/activities/{$activity->id}", [
            'is_all_day' => false,
            'duration' => 60,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_all_day', false)
        ->assertJsonPath('data.duration', 60);
});

test('superadmin can archive a park activity (soft-delete)', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/activities/{$activity->id}")
        ->assertNoContent();

    expect($activity->fresh()->trashed())->toBeTrue();
});

test('archive is blocked when an activity has upcoming confirmed bookings', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $schedule = $activity->schedules()->create([
        'date'       => now()->addDays(3)->toDateString(),
        'start_time' => '11:00:00',
        'end_time'   => '12:00:00',
        'status'     => \App\Models\ParkActivitySchedule::STATUS_SCHEDULED,
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
        ->deleteJson("/api/theme-parks/{$park->id}/activities/{$activity->id}")
        ->assertStatus(409)
        ->assertJsonPath('blocking_bookings', 1);

    expect($activity->fresh()->trashed())->toBeFalse();
});

test('restoring an activity while its parent park is archived returns 409', function () {
    $park = ThemePark::factory()->create();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);

    // Archive the park (cascades to activity).
    $park->delete();

    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/restore")
        ->assertStatus(409);
});

// PR 8 — max_capacity must not exceed park.capacity

test('activity create rejects max_capacity exceeding park capacity', function () {
    $park = ThemePark::factory()->create(['capacity' => 50]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities", [
            'name' => 'Big Coaster',
            'price' => 25,
            'duration' => 30,
            'max_capacity' => 51,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_capacity');
});

test('activity create accepts max_capacity equal to park capacity', function () {
    $park = ThemePark::factory()->create(['capacity' => 50]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities", [
            'name' => 'Right-Sized Coaster',
            'price' => 25,
            'duration' => 30,
            'max_capacity' => 50,
        ])
        ->assertCreated();
});

test('activity update rejects max_capacity exceeding park capacity', function () {
    $park = ThemePark::factory()->create(['capacity' => 50]);
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'max_capacity' => 30,
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/activities/{$activity->id}", [
            'max_capacity' => 60,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_capacity');
});
