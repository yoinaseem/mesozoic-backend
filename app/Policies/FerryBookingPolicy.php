<?php

namespace App\Policies;

use App\Models\FerryBooking;
use App\Models\User;

class FerryBookingPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('bookings.view');
    }

    public function view(User $user, FerryBooking $booking): bool
    {
        if (! $user->hasPermissionTo('bookings.view')) {
            return false;
        }

        if ($booking->reservation->user_id === $user->id) {
            return true;
        }

        return $user->hasRole('ferry-manager');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('bookings.create');
    }

    public function update(User $user, FerryBooking $booking): bool
    {
        return $user->hasPermissionTo('bookings.update')
            && $user->hasRole('ferry-manager');
    }

    /**
     * Cancellation is staff-only for ferry bookings — customers cannot
     * self-cancel (matches BeachBookingPolicy / ParkActivityBookingPolicy).
     */
    public function delete(User $user, FerryBooking $booking): bool
    {
        return $user->hasPermissionTo('bookings.cancel')
            && $user->hasRole('ferry-manager');
    }
}
