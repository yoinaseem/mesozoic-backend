<?php

namespace App\Policies;

use App\Models\Hotel;
use App\Models\User;

class HotelPolicy
{
    /**
     * Superadmins bypass every check.
     *
     * Returning `null` from before() lets Laravel fall through to the per-ability
     * method; returning true/false short-circuits.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    /**
     * Listing/reading hotels is public today (route is unauthenticated).
     * Kept here so $this->authorize('view', ...) is safe if called.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Hotel $hotel): bool
    {
        return true;
    }

    /**
     * Create/delete are superadmin-only — handled entirely by before().
     * These methods return false so non-superadmins are always denied.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Requires the hotels.update permission AND an assignment to this hotel.
     * Middleware handles the permission check on the route; this guards the
     * per-instance scope so a hotel-manager can only edit their own hotels.
     */
    public function update(User $user, Hotel $hotel): bool
    {
        return $user->hasPermissionTo('hotels.update') && $user->managesHotel($hotel);
    }

    public function delete(User $user, Hotel $hotel): bool
    {
        return false;
    }
}
