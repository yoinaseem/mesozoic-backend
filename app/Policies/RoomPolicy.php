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
}
