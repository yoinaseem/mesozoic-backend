<?php

namespace App\Policies;

use App\Models\BeachBooking;
use App\Models\User;

class BeachBookingPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('bookings.view');
    }

    public function view(User $user, BeachBooking $booking): bool
    {
        if (! $user->hasPermissionTo('bookings.view')) {
            return false;
        }

        if ($booking->reservation->user_id === $user->id) {
            return true;
        }

        return $user->hasRole('beach-manager');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('bookings.create');
    }

    public function update(User $user, BeachBooking $booking): bool
    {
        return $user->hasPermissionTo('bookings.update')
            && $user->hasRole('beach-manager');
    }

    /**
     * Cancellation is staff-only for beach bookings — customers cannot
     * self-cancel (diverges from ParkBookingPolicy by design).
     */
    public function delete(User $user, BeachBooking $booking): bool
    {
        return $user->hasPermissionTo('bookings.cancel')
            && $user->hasRole('beach-manager');
    }
}
