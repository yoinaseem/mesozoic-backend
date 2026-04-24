<?php

namespace App\Policies;

use App\Models\RoomBooking;
use App\Models\User;

class RoomBookingPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('bookings.view');
    }

    public function view(User $user, RoomBooking $booking): bool
    {
        if (! $user->hasPermissionTo('bookings.view')) {
            return false;
        }

        if ($booking->reservation->user_id === $user->id) {
            return true;
        }

        return $user->managesHotel($booking->hotel);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('bookings.create');
    }

    public function update(User $user, RoomBooking $booking): bool
    {
        return $user->hasPermissionTo('bookings.update')
            && $user->managesHotel($booking->hotel);
    }

    /**
     * Cancellation is staff-only — customers do not self-cancel room bookings.
     * Aligned with beach/park/ferry/park-activity bookings; customer-facing
     * UX is "contact staff" rather than a DELETE button.
     */
    public function delete(User $user, RoomBooking $booking): bool
    {
        if (! $user->hasPermissionTo('bookings.cancel')) {
            return false;
        }

        return $user->managesHotel($booking->hotel);
    }
}
