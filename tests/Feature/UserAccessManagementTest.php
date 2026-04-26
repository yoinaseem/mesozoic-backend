<?php

use App\Models\Hotel;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Auth gating
|--------------------------------------------------------------------------
*/

test('customer cannot sync another user\'s roles', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $victim = User::factory()->create();
    $victim->assignRole('customer');

    actingAs($customer)
        ->putJson("/api/users/{$victim->id}/roles", ['roles' => ['hotel-manager']])
        ->assertForbidden();

    expect($victim->fresh()->hasRole('customer'))->toBeTrue()
        ->and($victim->fresh()->hasRole('hotel-manager'))->toBeFalse();
});

test('customer cannot sync another user\'s direct permissions', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $victim = User::factory()->create();

    actingAs($customer)
        ->putJson("/api/users/{$victim->id}/permissions", ['permissions' => ['hotels.view']])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Roles + hotel-manager pivot
|--------------------------------------------------------------------------
*/

test('superadmin can sync roles and managed_hotels atomically', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $target = User::factory()->create();
    $target->assignRole('customer');

    $hotelA = Hotel::factory()->create();
    $hotelB = Hotel::factory()->create();

    actingAs($admin)
        ->putJson("/api/users/{$target->id}/roles", [
            'roles'          => ['hotel-manager'],
            'managed_hotels' => [$hotelA->id, $hotelB->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.roles', ['hotel-manager']);

    $target->refresh();
    expect($target->hasRole('hotel-manager'))->toBeTrue()
        ->and($target->hasRole('customer'))->toBeFalse()
        ->and($target->managedHotels->pluck('id')->all())
        ->toEqualCanonicalizing([$hotelA->id, $hotelB->id]);
});

test('losing hotel-manager role auto-clears managed_hotels pivot', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $hotel = Hotel::factory()->create();
    $manager->managedHotels()->attach($hotel->id);

    actingAs($admin)
        ->putJson("/api/users/{$manager->id}/roles", ['roles' => ['customer']])
        ->assertOk();

    expect($manager->fresh()->managedHotels)->toBeEmpty();
});

test('omitting managed_hotels while keeping hotel-manager preserves the pivot', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $hotel = Hotel::factory()->create();
    $manager->managedHotels()->attach($hotel->id);

    actingAs($admin)
        ->putJson("/api/users/{$manager->id}/roles", [
            'roles' => ['hotel-manager'],
            // managed_hotels intentionally omitted
        ])
        ->assertOk();

    expect($manager->fresh()->managedHotels->pluck('id')->all())
        ->toEqual([$hotel->id]);
});

test('passing empty managed_hotels while keeping hotel-manager clears pivot', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $hotel = Hotel::factory()->create();
    $manager->managedHotels()->attach($hotel->id);

    actingAs($admin)
        ->putJson("/api/users/{$manager->id}/roles", [
            'roles'          => ['hotel-manager'],
            'managed_hotels' => [],
        ])
        ->assertOk();

    expect($manager->fresh()->managedHotels)->toBeEmpty();
});

test('unknown role name is rejected with 422', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $target = User::factory()->create();

    actingAs($admin)
        ->putJson("/api/users/{$target->id}/roles", ['roles' => ['ghost-role']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['roles.0']);
});

/*
|--------------------------------------------------------------------------
| Lockout protection
|--------------------------------------------------------------------------
*/

test('admin cannot remove their own superadmin role', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    // A second superadmin so the last-superadmin guard isn't the trigger.
    $other = User::factory()->create();
    $other->assignRole('superadmin');

    actingAs($admin)
        ->putJson("/api/users/{$admin->id}/roles", ['roles' => ['customer']])
        ->assertStatus(409)
        ->assertJsonPath('message', 'You cannot remove your own superadmin role.');

    expect($admin->fresh()->hasRole('superadmin'))->toBeTrue();
});

test('cannot remove superadmin from the last user holding it', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $second = User::factory()->create();
    $second->assignRole('superadmin');

    // Acting as `second`, demote `admin` — `admin` is not the last superadmin
    // yet, so this should succeed.
    actingAs($second)
        ->putJson("/api/users/{$admin->id}/roles", ['roles' => ['customer']])
        ->assertOk();

    // Now `second` is the only superadmin. Have a fresh actor try to demote
    // them — it should fail with last-superadmin protection. We need an actor
    // with roles.manage who isn't the target; promote a third user.
    $third = User::factory()->create();
    $third->givePermissionTo('roles.manage');

    actingAs($third)
        ->putJson("/api/users/{$second->id}/roles", ['roles' => ['customer']])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Cannot remove the last superadmin.');

    expect($second->fresh()->hasRole('superadmin'))->toBeTrue();
});

test('non-last superadmin demotion succeeds', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $other = User::factory()->create();
    $other->assignRole('superadmin');

    actingAs($admin)
        ->putJson("/api/users/{$other->id}/roles", ['roles' => ['customer']])
        ->assertOk();

    expect($other->fresh()->hasRole('superadmin'))->toBeFalse()
        ->and($other->fresh()->hasRole('customer'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Direct permission grants
|--------------------------------------------------------------------------
*/

test('direct permission grant gives effective access not in role', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $target = User::factory()->create();
    $target->assignRole('customer');

    // `customer` does not include `hotels.view`.
    expect($target->hasPermissionTo('hotels.view'))->toBeFalse();

    actingAs($admin)
        ->putJson("/api/users/{$target->id}/permissions", [
            'permissions' => ['hotels.view'],
        ])
        ->assertOk()
        ->assertJsonPath('data.direct_permissions', ['hotels.view']);

    expect($target->fresh()->hasPermissionTo('hotels.view'))->toBeTrue();
});

test('syncing direct permissions to empty leaves role-derived intact', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $target = User::factory()->create();
    $target->assignRole('customer');
    $target->givePermissionTo('hotels.view');

    actingAs($admin)
        ->putJson("/api/users/{$target->id}/permissions", ['permissions' => []])
        ->assertOk()
        ->assertJsonPath('data.direct_permissions', []);

    $target->refresh();
    // hotels.view (direct) is gone but role-derived perms remain.
    expect($target->hasPermissionTo('hotels.view'))->toBeFalse()
        ->and($target->hasPermissionTo('bookings.view'))->toBeTrue()
        ->and($target->hasPermissionTo('bookings.create'))->toBeTrue();
});

test('unknown permission name is rejected with 422', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $target = User::factory()->create();

    actingAs($admin)
        ->putJson("/api/users/{$target->id}/permissions", [
            'permissions' => ['does.not.exist'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions.0']);
});
