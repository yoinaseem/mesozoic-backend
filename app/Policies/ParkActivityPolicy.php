<?php

namespace App\Policies;

use App\Models\ParkActivity;
use App\Models\User;

class ParkActivityPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ParkActivity $parkActivity): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('park.create');
    }

    public function update(User $user, ParkActivity $parkActivity): bool
    {
        return $user->hasPermissionTo('park.update');
    }

    public function delete(User $user, ParkActivity $parkActivity): bool
    {
        return false;
    }
}
