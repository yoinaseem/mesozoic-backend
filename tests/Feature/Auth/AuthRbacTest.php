<?php

use App\Models\Hotel;
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

// ---------------------------------------------------------------------------
// GET /auth/me/hotels
// ---------------------------------------------------------------------------

test('me/hotels requires authentication', function () {
    getJson('/api/auth/me/hotels')->assertUnauthorized();
});

test('customer me/hotels returns empty data', function () {
    $u = User::factory()->create();
    $u->assignRole('customer');
    Hotel::factory()->create();

    $this->actingAs($u)
        ->getJson('/api/auth/me/hotels')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('hotel-manager me/hotels returns only assigned hotels', function () {
    $assigned   = Hotel::factory()->create(['name' => 'Assigned One']);
    $assigned2  = Hotel::factory()->create(['name' => 'Assigned Two']);
    $unassigned = Hotel::factory()->create(['name' => 'Other Hotel']);

    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach([$assigned->id, $assigned2->id]);

    $response = $this->actingAs($manager)
        ->getJson('/api/auth/me/hotels')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($assigned->id);
    expect($ids)->toContain($assigned2->id);
    expect($ids)->not->toContain($unassigned->id);
    expect($response->json('data.0'))->toHaveKeys(['id', 'name']);
});

test('hotel-manager with no assignments gets an empty list', function () {
    Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');

    $this->actingAs($manager)
        ->getJson('/api/auth/me/hotels')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('superadmin me/hotels returns every live hotel', function () {
    $h1 = Hotel::factory()->create(['name' => 'Alpha']);
    $h2 = Hotel::factory()->create(['name' => 'Bravo']);
    $h3 = Hotel::factory()->create(['name' => 'Charlie']);

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $response = $this->actingAs($admin)
        ->getJson('/api/auth/me/hotels')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($h1->id, $h2->id, $h3->id);
});

test('archived hotels are excluded from superadmin me/hotels', function () {
    $live     = Hotel::factory()->create(['name' => 'Live']);
    $archived = Hotel::factory()->create(['name' => 'Archived']);
    $archived->delete();

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $response = $this->actingAs($admin)
        ->getJson('/api/auth/me/hotels')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($live->id);
    expect($ids)->not->toContain($archived->id);
});
