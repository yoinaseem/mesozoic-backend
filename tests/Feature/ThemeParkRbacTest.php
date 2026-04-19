<?php

use App\Models\ThemePark;
use App\Models\User;

use function Pest\Laravel\getJson;

test('public can list theme parks without auth', function () {
    ThemePark::factory()->count(2)->create();

    getJson('/api/theme-parks')->assertOk();
});

test('public can view a single theme park without auth', function () {
    $park = ThemePark::factory()->create();

    getJson("/api/theme-parks/{$park->id}")->assertOk();
});

test('unauthenticated user cannot create a theme park', function () {
    $this->postJson('/api/theme-parks', [
        'name' => 'Jurassic World',
        'images' => ['https://example.com/a.jpg'],
        'description' => 'Roarsome day out',
        'capacity' => 8000,
        'price' => 89.99,
        'contact_email' => 'hello@park.test',
        'contact_phone' => '+1-555-0100',
    ])->assertUnauthorized();
});

test('customer cannot create a theme park', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson('/api/theme-parks', [
            'name' => 'Jurassic World',
            'images' => ['https://example.com/a.jpg'],
            'description' => 'Roarsome day out',
            'capacity' => 8000,
            'price' => 89.99,
            'contact_email' => 'hello@park.test',
            'contact_phone' => '+1-555-0100',
        ])
        ->assertForbidden();
});

test('park-manager can create a theme park', function () {
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson('/api/theme-parks', [
            'name' => 'Jurassic World',
            'images' => ['https://example.com/a.jpg'],
            'description' => 'Roarsome day out',
            'capacity' => 8000,
            'price' => 89.99,
            'contact_email' => 'hello@park.test',
            'contact_phone' => '+1-555-0100',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Jurassic World');
});

test('superadmin can create a theme park', function () {
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->postJson('/api/theme-parks', [
            'name' => 'Cretaceous Coast',
            'images' => [],
            'description' => 'Admin-built park',
            'capacity' => 5000,
            'price' => 75,
            'contact_email' => 'admin@park.test',
            'contact_phone' => '+1-555-0200',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Cretaceous Coast');
});

test('park-manager can update a theme park', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->putJson("/api/theme-parks/{$park->id}", ['name' => 'Renamed Park'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Park');
});

test('customer cannot update a theme park', function () {
    $park = ThemePark::factory()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->putJson("/api/theme-parks/{$park->id}", ['name' => 'No Access'])
        ->assertForbidden();
});

test('superadmin can update any theme park', function () {
    $park = ThemePark::factory()->create();
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->putJson("/api/theme-parks/{$park->id}", ['name' => 'Admin Update'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Admin Update');
});

test('park-manager cannot delete a theme park', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->deleteJson("/api/theme-parks/{$park->id}")
        ->assertForbidden();
});

test('superadmin can delete a theme park', function () {
    $park = ThemePark::factory()->create();
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}")
        ->assertNoContent();
});
