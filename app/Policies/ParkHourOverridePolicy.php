<?php

namespace App\Policies;

use App\Models\ParkHourOverride;
use App\Models\User;

class ParkHourOverridePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ParkHourOverride $override): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('park.create');
    }

    public function update(User $user, ParkHourOverride $override): bool
    {
        return $user->hasPermissionTo('park.update');
    }

    public function delete(User $user, ParkHourOverride $override): bool
    {
        return false;
    }
}
