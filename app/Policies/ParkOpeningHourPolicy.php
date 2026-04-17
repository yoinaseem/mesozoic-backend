<?php

namespace App\Policies;

use App\Models\ParkOpeningHour;
use App\Models\User;

class ParkOpeningHourPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ParkOpeningHour $openingHour): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('park.create');
    }

    public function update(User $user, ParkOpeningHour $openingHour): bool
    {
        return $user->hasPermissionTo('park.update');
    }

    public function delete(User $user, ParkOpeningHour $openingHour): bool
    {
        return false;
    }
}
