<?php

namespace App\Policies;

use App\Models\ParkActivitySchedule;
use App\Models\User;

class ParkActivitySchedulePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ParkActivitySchedule $schedule): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('park.create');
    }

    public function update(User $user, ParkActivitySchedule $schedule): bool
    {
        return $user->hasPermissionTo('park.update');
    }

    public function delete(User $user, ParkActivitySchedule $schedule): bool
    {
        return false;
    }
}
