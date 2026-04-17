<?php

namespace App\Policies;

use App\Models\BeachActivitySchedule;
use App\Models\User;

class BeachActivitySchedulePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BeachActivitySchedule $schedule): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('beach.create');
    }

    public function update(User $user, BeachActivitySchedule $schedule): bool
    {
        return $user->hasPermissionTo('beach.update');
    }

    public function delete(User $user, BeachActivitySchedule $schedule): bool
    {
        return false;
    }
}
