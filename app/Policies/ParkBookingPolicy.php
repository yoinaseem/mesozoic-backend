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

        // Owner of the underlying reservation always sees their own tickets.
        if ($booking->roomBooking->reservation->user_id === $user->id) {
            return true;
        }

        // Park-manager role sees all park bookings (no per-park pivot today).
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

    public function delete(User $user, ParkBooking $booking): bool
    {
        if (! $user->hasPermissionTo('bookings.cancel')) {
            return false;
        }

        // Customer can cancel their own ticket only before the visit date.
        if ($booking->roomBooking->reservation->user_id === $user->id) {
            return now()->toDateString() < $booking->date->toDateString();
        }

        return $user->hasRole('park-manager');
    }
}
