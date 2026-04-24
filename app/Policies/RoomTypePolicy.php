<?php

namespace App\Policies;

use App\Models\RoomType;
use App\Models\User;

class RoomTypePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RoomType $roomType): bool
    {
        return true;
    }

    /**
     * Room-type mutations delegate to the parent hotel's manager set — if the
     * user manages the hotel, they can manage its room types.
     *
     * `create` is called with only the User since the RoomType doesn't exist
     * yet; the route is nested under a hotel binding, so the controller passes
     * the hotel explicitly via Gate::authorize or $this->authorize('create', [RoomType::class, $hotel]).
     */
    public function create(User $user, \App\Models\Hotel $hotel): bool
    {
        return $user->hasPermissionTo('room-types.create') && $user->managesHotel($hotel);
    }

    public function update(User $user, RoomType $roomType): bool
    {
        return $user->hasPermissionTo('room-types.update') && $user->managesHotel($roomType->hotel);
    }

    public function delete(User $user, RoomType $roomType): bool
    {
        return $user->hasPermissionTo('room-types.delete') && $user->managesHotel($roomType->hotel);
    }

    /**
     * Restore mirrors delete. Resolve the hotel via withTrashed in case the
     * parent is also archived, otherwise the belongsTo returns null and
     * managesHotel would get handed a null.
     */
    public function restore(User $user, RoomType $roomType): bool
    {
        $hotel = \App\Models\Hotel::withTrashed()->find($roomType->hotel_id);

        return $user->hasPermissionTo('room-types.delete')
            && $hotel !== null
            && $user->managesHotel($hotel);
    }
}
