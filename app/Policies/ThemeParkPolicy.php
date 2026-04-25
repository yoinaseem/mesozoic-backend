<?php

namespace App\Policies;

use App\Models\ThemePark;
use App\Models\User;

class ThemeParkPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ThemePark $themePark): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('park.create');
    }

    public function update(User $user, ThemePark $themePark): bool
    {
        return $user->hasPermissionTo('park.update');
    }

    public function delete(User $user, ThemePark $themePark): bool
    {
        return false;
    }

    /**
     * Restore mirrors delete — superadmin-only via before(). Non-superadmins
     * hit false here.
     */
    public function restore(User $user, ThemePark $themePark): bool
    {
        return false;
    }
}
