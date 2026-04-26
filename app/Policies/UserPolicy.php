<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Superadmins bypass every check; all other paths fall through to the
     * ability methods, which by default deny everyone except self-access.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Self-view escape hatch: a user can always inspect their own record,
     * even without the users.view permission.
     */
    public function view(User $user, User $model): bool
    {
        return $user->is($model);
    }

    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Self-update escape hatch so users can edit their own profile.
     */
    public function update(User $user, User $model): bool
    {
        return $user->is($model);
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Restore mirrors delete — superadmin-only, handled by before().
     * Non-superadmins always hit false here.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Sync a user's roles + managed-hotel pivot. Gated by `roles.manage`;
     * superadmin bypass via before(). Self-lockout and last-superadmin
     * invariants are enforced inside the controller (need transaction scope).
     */
    public function assignRoles(User $user, User $model): bool
    {
        return $user->hasPermissionTo('roles.manage');
    }

    /**
     * Sync a user's direct (non-role-derived) permission grants. Same gate
     * as assignRoles.
     */
    public function assignPermissions(User $user, User $model): bool
    {
        return $user->hasPermissionTo('roles.manage');
    }
}
