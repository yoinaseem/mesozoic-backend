<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
|--------------------------------------------------------------------------
| Auth gating
|--------------------------------------------------------------------------
*/

test('unauthenticated cannot list roles', function () {
    getJson('/api/roles')->assertUnauthorized();
});

test('customer cannot list roles', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    actingAs($customer)->getJson('/api/roles')->assertForbidden();
});

test('superadmin can list roles with permissions, is_system, and users_count', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $response = actingAs($admin)->getJson('/api/roles')->assertOk();

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('superadmin', 'hotel-manager', 'customer');

    $superadminRow = collect($response->json('data'))->firstWhere('name', 'superadmin');
    expect($superadminRow)->not->toBeNull()
        ->and($superadminRow['is_system'])->toBeTrue()
        ->and($superadminRow['users_count'])->toBe(1)
        ->and($superadminRow['permissions'])->toContain('roles.manage');
});

test('superadmin can list permissions catalogue', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $response = actingAs($admin)->getJson('/api/permissions')->assertOk();

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('roles.manage', 'hotels.view', 'bookings.create');
});

/*
|--------------------------------------------------------------------------
| Role CRUD
|--------------------------------------------------------------------------
*/

test('superadmin can create a custom role with permissions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    actingAs($admin)
        ->postJson('/api/roles', [
            'name'        => 'reports-viewer',
            'permissions' => ['hotels.view', 'bookings.view'],
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'reports-viewer')
        ->assertJsonPath('data.is_system', false);

    $role = Role::findByName('reports-viewer', 'web');
    expect($role->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(['hotels.view', 'bookings.view']);
});

test('create rejects invalid kebab-case role name', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    actingAs($admin)
        ->postJson('/api/roles', ['name' => 'Bad_Name'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('create rejects duplicate role name', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    actingAs($admin)
        ->postJson('/api/roles', ['name' => 'customer'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('create rejects unknown permission', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    actingAs($admin)
        ->postJson('/api/roles', [
            'name'        => 'mystery-role',
            'permissions' => ['does.not.exist'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions.0']);
});

test('superadmin can edit permissions on a system role', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $customer = Role::findByName('customer');

    actingAs($admin)
        ->putJson("/api/roles/{$customer->id}", [
            'permissions' => ['bookings.view'],
        ])
        ->assertOk();

    expect($customer->fresh()->permissions->pluck('name')->all())
        ->toEqual(['bookings.view']);
});

test('renaming a system role returns 409', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $customer = Role::findByName('customer');

    actingAs($admin)
        ->putJson("/api/roles/{$customer->id}", ['name' => 'guest'])
        ->assertStatus(409)
        ->assertJsonPath('role', 'customer');

    expect($customer->fresh()->name)->toBe('customer');
});

test('renaming a custom role succeeds', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $role = Role::create(['name' => 'reports-viewer', 'guard_name' => 'web']);

    actingAs($admin)
        ->putJson("/api/roles/{$role->id}", ['name' => 'analytics-viewer'])
        ->assertOk()
        ->assertJsonPath('data.name', 'analytics-viewer');
});

test('deleting a system role returns 409', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    // Pass `'web'` explicitly: once auth:sanctum runs, Laravel mutates
    // config('auth.defaults.guard') to 'sanctum' for the rest of the test,
    // and Spatie's findByName falls back to that. The kwarg keeps lookups
    // deterministic regardless of where in the test we are.
    $customer = Role::findByName('customer', 'web');

    actingAs($admin)
        ->deleteJson("/api/roles/{$customer->id}")
        ->assertStatus(409)
        ->assertJsonPath('role', 'customer');

    expect(Role::findByName('customer', 'web'))->not->toBeNull();
});

test('deleting a role with assigned users returns 409 with users_count', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $role = Role::create(['name' => 'reports-viewer', 'guard_name' => 'web']);
    $holder = User::factory()->create();
    $holder->assignRole('reports-viewer');

    actingAs($admin)
        ->deleteJson("/api/roles/{$role->id}")
        ->assertStatus(409)
        ->assertJsonPath('users_count', 1);

    expect(Role::find($role->id))->not->toBeNull();
});

test('deleting an unused custom role returns 204', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $role = Role::create(['name' => 'reports-viewer', 'guard_name' => 'web']);

    actingAs($admin)
        ->deleteJson("/api/roles/{$role->id}")
        ->assertNoContent();

    expect(Role::find($role->id))->toBeNull();
});
