<?php

use App\Models\Hotel;
use App\Models\User;

use function Pest\Laravel\getJson;

test('public can list hotels without auth', function () {
    Hotel::factory()->count(2)->create();

    getJson('/api/hotels')->assertOk();
});

test('public can view a single hotel without auth', function () {
    $hotel = Hotel::factory()->create();

    getJson("/api/hotels/{$hotel->id}")->assertOk();
});

test('unauthenticated user cannot create a hotel', function () {
    $this->postJson('/api/hotels', [
        'name'        => 'Test',
        'address'     => 'Nowhere',
        'description' => 'A test hotel',
    ])->assertUnauthorized();
});

test('customer cannot create a hotel', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $this->actingAs($customer)
        ->postJson('/api/hotels', [
            'name'        => 'Test',
            'address'     => 'Nowhere',
            'description' => 'A test hotel',
        ])
        ->assertForbidden();
});

test('hotel-manager cannot create a hotel (superadmin-only)', function () {
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');

    $this->actingAs($manager)
        ->postJson('/api/hotels', [
            'name'        => 'Test',
            'address'     => 'Nowhere',
            'description' => 'A test hotel',
        ])
        ->assertForbidden();
});

test('superadmin can create a hotel', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->postJson('/api/hotels', [
            'name'        => 'Admin Hotel',
            'address'     => 'Central',
            'description' => 'Built by superadmin',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Admin Hotel');
});

test('unassigned hotel-manager cannot update a hotel', function () {
    $hotel   = Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');

    $this->actingAs($manager)
        ->putJson("/api/hotels/{$hotel->id}", ['name' => 'Hijacked'])
        ->assertForbidden();
});

test('assigned hotel-manager can update their hotel', function () {
    $hotel   = Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($hotel);

    $this->actingAs($manager)
        ->putJson("/api/hotels/{$hotel->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');
});

test('assigned hotel-manager cannot update an unrelated hotel', function () {
    $owned  = Hotel::factory()->create();
    $other  = Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($owned);

    $this->actingAs($manager)
        ->putJson("/api/hotels/{$other->id}", ['name' => 'Nope'])
        ->assertForbidden();
});

test('superadmin can update any hotel', function () {
    $hotel = Hotel::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->putJson("/api/hotels/{$hotel->id}", ['name' => 'Admin Edit'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Admin Edit');
});

test('hotel-manager cannot delete a hotel (superadmin-only)', function () {
    $hotel   = Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($hotel);

    $this->actingAs($manager)
        ->deleteJson("/api/hotels/{$hotel->id}")
        ->assertForbidden();
});

test('superadmin can delete a hotel', function () {
    $hotel = Hotel::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->deleteJson("/api/hotels/{$hotel->id}")
        ->assertNoContent();
});
