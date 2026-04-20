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

    public function view(User $user, Reservation $reservation): bool
    {
        if (! $user->hasPermissionTo('bookings.view')) {
            return false;
        }

        if ($reservation->user_id === $user->id) {
            return true;
        }

        return $user->managedHotels()
            ->whereIn('hotels.id', $reservation->roomBookings()->select('hotel_id'))
            ->exists();
    }
}
