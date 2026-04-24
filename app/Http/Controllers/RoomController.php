<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoomResource;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomBooking;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    use AuthorizesRequests;

    public function index(Hotel $hotel): AnonymousResourceCollection
    {
        $rooms = $hotel->rooms()->with('roomType')->paginate(10);

        return RoomResource::collection($rooms);
    }

    public function show(Hotel $hotel, Room $room): RoomResource
    {
        $room->load('roomType');

        return new RoomResource($room);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('create', [Room::class, $hotel]);

        $data = $request->validate([
            'room_type_id' => ['required', Rule::exists('room_types', 'id')->where('hotel_id', $hotel->id)],
            // Uniqueness is scoped to live (non-archived) rooms so a room_no
            // can be reused after its previous holder was archived.
            'room_no'      => [
                'required', 'string', 'max:255',
                Rule::unique('rooms')->where('hotel_id', $hotel->id)->whereNull('deleted_at'),
            ],
        ]);

        $room = $hotel->rooms()->create($data);
        $room->load('roomType');

        return (new RoomResource($room))->response()->setStatusCode(201);
    }

    public function update(Request $request, Hotel $hotel, Room $room): RoomResource
    {
        $this->authorize('update', $room);

        $data = $request->validate([
            'room_type_id' => ['sometimes', Rule::exists('room_types', 'id')->where('hotel_id', $room->hotel_id)],
            'room_no'      => [
                'sometimes', 'string', 'max:255',
                Rule::unique('rooms')
                    ->where('hotel_id', $room->hotel_id)
                    ->whereNull('deleted_at')
                    ->ignore($room->id),
            ],
        ]);

        $room->update($data);
        $room->load('roomType');

        return new RoomResource($room);
    }

    public function destroy(Hotel $hotel, Room $room): JsonResponse
    {
        $this->authorize('delete', $room);

        $blocking = RoomBooking::query()
            ->upcomingActive()
            ->where('room_id', $room->id)
            ->count();

        if ($blocking > 0) {
            return response()->json([
                'message'           => 'Cannot archive a room with upcoming or in-progress bookings.',
                'blocking_bookings' => $blocking,
            ], 409);
        }

        $room->delete();

        return response()->json(null, 204);
    }

    /**
     * Restore a room. Parent hotel + room type must both be live — if either
     * is archived, the caller must restore the parent first (that
     * cascade-restore will bring this room back too).
     */
    public function restore(Hotel $hotel, Room $room): RoomResource
    {
        $this->authorize('restore', $room);

        if ($hotel->trashed()) {
            abort(response()->json([
                'message' => 'Restore the parent hotel first.',
            ], 409));
        }

        $roomType = $room->roomType()->withTrashed()->first();
        if ($roomType && $roomType->trashed()) {
            abort(response()->json([
                'message' => 'Restore the parent room type first.',
            ], 409));
        }

        if ($room->trashed()) {
            $room->restore();
        }

        $room->load('roomType');

        return new RoomResource($room);
    }
}
