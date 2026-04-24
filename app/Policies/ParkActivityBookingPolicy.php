<?php

namespace App\Policies;

use App\Models\ParkActivityBooking;
use App\Models\User;

class ParkActivityBookingPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('bookings.view');
    }

    public function view(User $user, ParkActivityBooking $booking): bool
    {
        if (! $user->hasPermissionTo('bookings.view')) {
            return false;
        }

        if ($booking->reservation->user_id === $user->id) {
            return true;
        }

        return $user->hasRole('park-manager');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('bookings.create');
    }

    public function update(User $user, ParkActivityBooking $booking): bool
    {
        return $user->hasPermissionTo('bookings.update')
            && $user->hasRole('park-manager');
    }

    /**
     * Cancellation is staff-only for park activity bookings — customers
     * cannot self-cancel (matches BeachBookingPolicy).
     */
    public function delete(User $user, ParkActivityBooking $booking): bool
    {
        return $user->hasPermissionTo('bookings.cancel')
            && $user->hasRole('park-manager');
    }
}
