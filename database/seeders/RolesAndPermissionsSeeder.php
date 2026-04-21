<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Seed roles and permissions.
     *
     * Idempotent: safe to run on every deploy. Uses firstOrCreate + syncPermissions
     * so re-running will not duplicate rows or drop additional assignments made
     * outside of this seeder (e.g., future admin-panel grants).
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Flat `resource.action` permission strings. Seed all day one so the catalogue
        // is stable even for modules that haven't shipped yet.
        $permissions = [
            // Active now
            'hotels.view', 'hotels.create', 'hotels.update', 'hotels.delete',
            'room-types.view', 'room-types.create', 'room-types.update', 'room-types.delete',
            'rooms.view', 'rooms.create', 'rooms.update', 'rooms.delete',
            'users.view', 'users.create', 'users.update', 'users.delete',

            // Forward-compatible (wiring lands with each module)
            'ferry.view', 'ferry.create', 'ferry.update', 'ferry.delete',
            'park.view', 'park.create', 'park.update', 'park.delete',
            'beach.view', 'beach.create', 'beach.update', 'beach.delete',
            'bookings.view', 'bookings.create', 'bookings.update', 'bookings.delete', 'bookings.cancel',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $superadmin    = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $hotelManager  = Role::firstOrCreate(['name' => 'hotel-manager', 'guard_name' => 'web']);
        $ferryManager  = Role::firstOrCreate(['name' => 'ferry-manager', 'guard_name' => 'web']);
        $parkManager   = Role::firstOrCreate(['name' => 'park-manager', 'guard_name' => 'web']);
        $beachManager  = Role::firstOrCreate(['name' => 'beach-manager', 'guard_name' => 'web']);
        $customer      = Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        // Superadmin: everything.
        $superadmin->syncPermissions(Permission::all());

        // Hotel-manager: view own hotels, update them, full CRUD on room types and rooms.
        // Hotel create/delete stays with superadmin by design.
        $hotelManager->syncPermissions([
            'hotels.view', 'hotels.update',
            'room-types.view', 'room-types.create', 'room-types.update', 'room-types.delete',
            'rooms.view', 'rooms.create', 'rooms.update', 'rooms.delete',
            'bookings.view', 'bookings.create', 'bookings.update', 'bookings.delete', 'bookings.cancel',
        ]);

        // Module managers get view, create, and update on their module. Delete stays
        // with superadmin. Unlike hotel-manager (which is pivot-scoped), these roles
        // manage all resources in their module without per-instance ownership.
        $ferryManager->syncPermissions(['ferry.view', 'ferry.create', 'ferry.update']);
        $parkManager->syncPermissions([
            'park.view', 'park.create', 'park.update',
            // Park-managers need bookings.* to manage park day-pass bookings
            // (see /api/park-bookings). Matches hotel-manager's bookings scope.
            'bookings.view', 'bookings.update', 'bookings.cancel',
        ]);
        $beachManager->syncPermissions(['beach.view', 'beach.create', 'beach.update']);

        // Customer: self-service booking flow — create, view, and cancel their own bookings.
        $customer->syncPermissions([
            'bookings.view', 'bookings.create', 'bookings.cancel',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
