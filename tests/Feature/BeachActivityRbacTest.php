<?php

use App\Models\BeachActivity;
use App\Models\User;

use function Pest\Laravel\getJson;

test('public can list beach activities without auth', function () {
    BeachActivity::factory()->count(2)->create();

    getJson('/api/beach-activities')->assertOk();
});

test('public can view a single beach activity without auth', function () {
    $activity = BeachActivity::factory()->create();

    getJson("/api/beach-activities/{$activity->id}")->assertOk();
});

test('unauthenticated user cannot create a beach activity', function () {
    $this->postJson('/api/beach-activities', [
        'name' => 'Jet Ski',
        'description' => 'Sea fun',
        'price' => 150,
        'capacity' => 10,
        'duration' => 60,
    ])->assertUnauthorized();
});

test('customer cannot create a beach activity', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson('/api/beach-activities', [
            'name' => 'Jet Ski',
            'description' => 'Sea fun',
            'price' => 150,
            'capacity' => 10,
            'duration' => 60,
        ])
        ->assertForbidden();
});

test('beach-manager can create a beach activity', function () {
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->postJson('/api/beach-activities', [
            'name' => 'Jet Ski',
            'description' => 'Sea fun',
            'price' => 150,
            'capacity' => 10,
            'duration' => 60,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Jet Ski');
});

test('superadmin can create a beach activity', function () {
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->postJson('/api/beach-activities', [
            'name' => 'Parasailing',
            'description' => 'Air and sea',
            'price' => 200,
            'capacity' => 6,
            'duration' => 45,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Parasailing');
});

test('beach-manager can update a beach activity', function () {
    $activity = BeachActivity::factory()->create();
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->putJson("/api/beach-activities/{$activity->id}", ['name' => 'Updated Activity'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Activity');
});

test('customer cannot update a beach activity', function () {
    $activity = BeachActivity::factory()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->putJson("/api/beach-activities/{$activity->id}", ['name' => 'No Access'])
        ->assertForbidden();
});

test('superadmin can update any beach activity', function () {
    $activity = BeachActivity::factory()->create();
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->putJson("/api/beach-activities/{$activity->id}", ['name' => 'Admin Update'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Admin Update');
});

test('beach-manager cannot delete a beach activity', function () {
    $activity = BeachActivity::factory()->create();
    $manager = User::factory()->beachManager()->create();

    $this->actingAs($manager)
        ->deleteJson("/api/beach-activities/{$activity->id}")
        ->assertForbidden();
});

test('superadmin can delete a beach activity', function () {
    $activity = BeachActivity::factory()->create();
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/beach-activities/{$activity->id}")
        ->assertNoContent();
});
