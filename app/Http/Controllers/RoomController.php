<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoomResource;
use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    public function index(Hotel $hotel): AnonymousResourceCollection
    {
        $rooms = $hotel->rooms()->with('roomType')->paginate(15);

        return RoomResource::collection($rooms);
    }

    public function show(Hotel $hotel, Room $room): RoomResource
    {
        $room->load('roomType');

        return new RoomResource($room);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', Rule::exists('room_types', 'id')->where('hotel_id', $hotel->id)],
            'room_no'      => ['required', 'string', 'max:255', Rule::unique('rooms')->where('hotel_id', $hotel->id)],
        ]);

        $room = $hotel->rooms()->create($data);
        $room->load('roomType');

        return (new RoomResource($room))->response()->setStatusCode(201);
    }

    public function update(Request $request, Hotel $hotel, Room $room): RoomResource
    {
        $data = $request->validate([
            'room_type_id' => ['sometimes', Rule::exists('room_types', 'id')->where('hotel_id', $room->hotel_id)],
            'room_no'      => ['sometimes', 'string', 'max:255', Rule::unique('rooms')->where('hotel_id', $room->hotel_id)->ignore($room->id)],
        ]);

        $room->update($data);
        $room->load('roomType');

        return new RoomResource($room);
    }

    public function destroy(Hotel $hotel, Room $room): JsonResponse
    {
        $room->delete();

        return response()->json(null, 204);
    }
}
