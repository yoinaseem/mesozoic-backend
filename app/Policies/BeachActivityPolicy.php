<?php

namespace App\Policies;

use App\Models\BeachActivity;
use App\Models\User;

class BeachActivityPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BeachActivity $activity): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('beach.create');
    }

    public function update(User $user, BeachActivity $activity): bool
    {
        return $user->hasPermissionTo('beach.update');
    }

    public function delete(User $user, BeachActivity $activity): bool
    {
        return false;
    }
}
