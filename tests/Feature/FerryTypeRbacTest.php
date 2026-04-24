<?php

use App\Models\FerryType;
use App\Models\User;

function ftCustomer(): User
{
    $u = User::factory()->create();
    $u->assignRole('customer');

    return $u;
}

function ftFerryManager(): User
{
    $u = User::factory()->create();
    $u->assignRole('ferry-manager');

    return $u;
}

function ftSuperadmin(): User
{
    $u = User::factory()->create();
    $u->assignRole('superadmin');

    return $u;
}

test('public can list ferry types without auth', function () {
    FerryType::factory()->create();

    $this->getJson('/api/ferry-types')->assertOk();
});

test('public can view a single ferry type without auth', function () {
    $type = FerryType::factory()->create();

    $this->getJson("/api/ferry-types/{$type->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $type->id);
});

test('unauthenticated user cannot create a ferry type', function () {
    $this->postJson('/api/ferry-types', [
        'name' => 'Premium',
        'capacity' => 50,
        'price' => 100,
    ])->assertUnauthorized();
});

test('customer cannot create a ferry type', function () {
    $this->actingAs(ftCustomer())
        ->postJson('/api/ferry-types', [
            'name' => 'Premium',
            'capacity' => 50,
            'price' => 100,
        ])
        ->assertForbidden();
});

test('ferry-manager can create a ferry type', function () {
    $this->actingAs(ftFerryManager())
        ->postJson('/api/ferry-types', [
            'name' => 'Air Conditioned',
            'description' => 'AC saloon ferry',
            'capacity' => 80,
            'price' => 60,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Air Conditioned')
        ->assertJsonPath('data.capacity', 80)
        ->assertJsonPath('data.price', 60);
});

test('ferry-manager can update a ferry type', function () {
    $type = FerryType::factory()->create(['price' => 50]);

    $this->actingAs(ftFerryManager())
        ->patchJson("/api/ferry-types/{$type->id}", ['price' => 75])
        ->assertOk()
        ->assertJsonPath('data.price', 75);
});

test('customer cannot update a ferry type', function () {
    $type = FerryType::factory()->create();

    $this->actingAs(ftCustomer())
        ->patchJson("/api/ferry-types/{$type->id}", ['price' => 999])
        ->assertForbidden();
});

test('ferry-manager cannot delete a ferry type', function () {
    $type = FerryType::factory()->create();

    $this->actingAs(ftFerryManager())
        ->deleteJson("/api/ferry-types/{$type->id}")
        ->assertForbidden();
});

test('superadmin can delete a ferry type', function () {
    $type = FerryType::factory()->create();

    $this->actingAs(ftSuperadmin())
        ->deleteJson("/api/ferry-types/{$type->id}")
        ->assertNoContent();
});
