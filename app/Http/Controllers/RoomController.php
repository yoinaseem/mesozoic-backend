<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    public function index(): JsonResponse
    {
        $rooms = Room::with(['hotel', 'roomType'])->get();

        return response()->json($rooms);
    }

    public function show(Room $room): JsonResponse
    {
        $room->load(['hotel', 'roomType']);

        return response()->json($room);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hotel_id'     => ['required', 'exists:hotels,id'],
            'room_type_id' => ['required', 'exists:room_types,id'],
            'room_no'      => ['required', 'string', 'max:255', Rule::unique('rooms')->where('hotel_id', $request->hotel_id)],
        ]);

        $room = Room::create($data);

        return response()->json($room->load(['hotel', 'roomType']), 201);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'hotel_id'     => ['sometimes', 'exists:hotels,id'],
            'room_type_id' => ['sometimes', 'exists:room_types,id'],
            'room_no'      => ['sometimes', 'string', 'max:255', Rule::unique('rooms')->where('hotel_id', $request->hotel_id)->ignore($room->id)],
        ]);

        $room->update($data);

        return response()->json($room->load(['hotel', 'roomType']));
    }

    public function destroy(Room $room): JsonResponse
    {
        $room->delete();

        return response()->json(null, 204);
    }
}
