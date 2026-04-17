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
