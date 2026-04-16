<?php

use App\Models\User;

use function Pest\Laravel\getJson;

test('unauthenticated user cannot list users', function () {
    getJson('/api/users')->assertUnauthorized();
});

test('customer cannot list users', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $this->actingAs($customer)
        ->getJson('/api/users')
        ->assertForbidden();
});

test('superadmin can list users', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->getJson('/api/users')
        ->assertOk();
});

test('superadmin can delete a user', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');
    $victim = User::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/users/{$victim->id}")
        ->assertNoContent();
});

test('user can view themselves', function () {
    $user = User::factory()->create();
    $user->assignRole('customer');

    $this->actingAs($user)
        ->getJson("/api/users/{$user->id}")
        ->assertOk();
});

test('user cannot view another user', function () {
    $user = User::factory()->create();
    $user->assignRole('customer');
    $other = User::factory()->create();

    $this->actingAs($user)
        ->getJson("/api/users/{$other->id}")
        ->assertForbidden();
});

test('user can update themselves', function () {
    $user = User::factory()->create();
    $user->assignRole('customer');

    $this->actingAs($user)
        ->putJson("/api/users/{$user->id}", ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name');
});

test('user cannot delete themselves', function () {
    $user = User::factory()->create();
    $user->assignRole('customer');

    $this->actingAs($user)
        ->deleteJson("/api/users/{$user->id}")
        ->assertForbidden();
});
