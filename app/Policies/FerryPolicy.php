<?php

namespace App\Policies;

use App\Models\Ferry;
use App\Models\User;

class FerryPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ferry $ferry): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ferry.create');
    }

    public function update(User $user, Ferry $ferry): bool
    {
        return $user->hasPermissionTo('ferry.update');
    }

    public function delete(User $user, Ferry $ferry): bool
    {
        return false;
    }
}
