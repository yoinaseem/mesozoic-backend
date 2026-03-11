<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    public function index(Hotel $hotel): JsonResponse
    {
        $rooms = $hotel->rooms()->with('roomType')->paginate(15);

        return response()->json($rooms);
    }

    public function show(Hotel $hotel, Room $room): JsonResponse
    {
        $room->load('roomType');

        return response()->json($room);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', Rule::exists('room_types', 'id')->where('hotel_id', $hotel->id)],
            'room_no'      => ['required', 'string', 'max:255', Rule::unique('rooms')->where('hotel_id', $hotel->id)],
        ]);

        $room = $hotel->rooms()->create($data);

        return response()->json($room->load('roomType'), 201);
    }

    public function update(Request $request, Hotel $hotel, Room $room): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['sometimes', Rule::exists('room_types', 'id')->where('hotel_id', $room->hotel_id)],
            'room_no'      => ['sometimes', 'string', 'max:255', Rule::unique('rooms')->where('hotel_id', $room->hotel_id)->ignore($room->id)],
        ]);

        $room->update($data);

        return response()->json($room->load('roomType'));
    }

    public function destroy(Hotel $hotel, Room $room): JsonResponse
    {
        $room->delete();

        return response()->json(null, 204);
    }
}
