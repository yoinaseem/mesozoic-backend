<?php

use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

test('register assigns the customer role and returns roles/permissions on the response', function () {
    $response = postJson('/api/auth/register', [
        'name'                  => 'Alice',
        'email'                 => 'alice@example.test',
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('user.email', 'alice@example.test')
        ->assertJsonPath('user.roles.0', 'customer')
        ->assertJsonStructure(['token', 'user' => ['id', 'email', 'roles', 'permissions']]);

    $user = User::where('email', 'alice@example.test')->firstOrFail();
    expect($user->hasRole('customer'))->toBeTrue();
});

test('login returns roles/permissions on the user payload', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);
    $user->assignRole('customer');

    postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'password123',
    ])
        ->assertOk()
        ->assertJsonPath('user.roles.0', 'customer')
        ->assertJsonStructure(['token', 'user' => ['id', 'email', 'roles', 'permissions']]);
});

test('me returns roles and permissions for the authenticated user', function () {
    $user = User::factory()->create();
    $user->assignRole('hotel-manager');

    $response = $this->actingAs($user)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonPath('user.roles.0', 'hotel-manager');

    expect($response->json('user.permissions'))->toContain('hotels.update');
});

test('me requires authentication', function () {
    getJson('/api/auth/me')->assertUnauthorized();
});
