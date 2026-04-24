<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Room $room): bool
    {
        return true;
    }

    public function create(User $user, \App\Models\Hotel $hotel): bool
    {
        return $user->hasPermissionTo('rooms.create') && $user->managesHotel($hotel);
    }

    public function update(User $user, Room $room): bool
    {
        return $user->hasPermissionTo('rooms.update') && $user->managesHotel($room->hotel);
    }

    public function delete(User $user, Room $room): bool
    {
        return $user->hasPermissionTo('rooms.delete') && $user->managesHotel($room->hotel);
    }

    /**
     * Restore mirrors delete. The parent hotel may itself be archived at
     * this point, so we resolve it via withTrashed so managesHotel can do
     * its pivot check.
     */
    public function restore(User $user, Room $room): bool
    {
        $hotel = \App\Models\Hotel::withTrashed()->find($room->hotel_id);

        return $user->hasPermissionTo('rooms.delete')
            && $hotel !== null
            && $user->managesHotel($hotel);
    }
}
