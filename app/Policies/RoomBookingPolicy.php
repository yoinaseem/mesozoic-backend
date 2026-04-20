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

    public function delete(User $user, RoomBooking $booking): bool
    {
        if (! $user->hasPermissionTo('bookings.cancel')) {
            return false;
        }

        if ($booking->reservation->user_id === $user->id) {
            return now()->toDateString() < $booking->check_in_date->toDateString();
        }

        return $user->managesHotel($booking->hotel);
    }
}
