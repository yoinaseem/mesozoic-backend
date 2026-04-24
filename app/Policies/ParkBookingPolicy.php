<?php

namespace App\Policies;

use App\Models\ParkBooking;
use App\Models\User;

class ParkBookingPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('bookings.view');
    }

    public function view(User $user, ParkBooking $booking): bool
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

    public function update(User $user, ParkBooking $booking): bool
    {
        return $user->hasPermissionTo('bookings.update')
            && $user->hasRole('park-manager');
    }

    /**
     * Cancellation is staff-only for park bookings — customers cannot
     * self-cancel. Unified with room/beach/ferry/park-activity policies so
     * every booking type directs customers to "contact staff" for cancellation.
     */
    public function delete(User $user, ParkBooking $booking): bool
    {
        return $user->hasPermissionTo('bookings.cancel')
            && $user->hasRole('park-manager');
    }
}
