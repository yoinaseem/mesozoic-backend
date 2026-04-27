<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('bookings.view');
    }

    /**
     * A non-superadmin caller may view a reservation when:
     *   - they are the customer who owns it, OR
     *   - they hold a domain-manager role and the reservation touches a
     *     booking in that domain (parallel to ReservationController's
     *     applyVisibilityScope).
     */
    public function view(User $user, Reservation $reservation): bool
    {
        if (! $user->hasPermissionTo('bookings.view')) {
            return false;
        }

        if ($reservation->user_id === $user->id) {
            return true;
        }

        if ($user->hasRole('hotel-manager')) {
            $matches = $user->managedHotels()
                ->whereIn('hotels.id', $reservation->roomBookings()->select('hotel_id'))
                ->exists();
            if ($matches) {
                return true;
            }
        }

        if ($user->hasRole('park-manager')) {
            if ($reservation->parkBookings()->exists()
                || $reservation->parkActivityBookings()->exists()) {
                return true;
            }
        }

        if ($user->hasRole('beach-manager')) {
            if ($reservation->beachBookings()->exists()) {
                return true;
            }
        }

        if ($user->hasRole('ferry-manager')) {
            if ($reservation->ferryBookings()->exists()) {
                return true;
            }
        }

        return false;
    }
}
