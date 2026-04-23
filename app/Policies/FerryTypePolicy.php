<?php

namespace App\Policies;

use App\Models\FerryType;
use App\Models\User;

class FerryTypePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FerryType $ferryType): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ferry.create');
    }

    public function update(User $user, FerryType $ferryType): bool
    {
        return $user->hasPermissionTo('ferry.update');
    }

    /**
     * Delete stays with superadmin — ferry-managers don't hold ferry.delete
     * (matches the role catalogue in RolesAndPermissionsSeeder). Only the
     * before() bypass lets superadmin through.
     */
    public function delete(User $user, FerryType $ferryType): bool
    {
        return false;
    }
}
