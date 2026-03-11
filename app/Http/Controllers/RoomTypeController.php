<?php

namespace App\Http\Controllers;

use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $roomTypes = RoomType::with(['hotel', 'rooms'])->get();

        return response()->json($roomTypes);
    }

    public function show(RoomType $roomType): JsonResponse
    {
        $roomType->load(['hotel', 'rooms']);

        return response()->json($roomType);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hotel_id'    => ['required', 'exists:hotels,id'],
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image'       => ['nullable', 'string', 'max:255'],
            'capacity'    => ['nullable', 'integer', 'min:1'],
            'price'       => ['nullable', 'numeric', 'min:0'],
            'amenities'   => ['nullable', 'array'],
            'amenities.*' => ['string'],
        ]);

        $roomType = RoomType::create($data);

        return response()->json($roomType->load(['hotel', 'rooms']), 201);
    }

    public function update(Request $request, RoomType $roomType): JsonResponse
    {
        $data = $request->validate([
            'hotel_id'    => ['sometimes', 'exists:hotels,id'],
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'image'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity'    => ['sometimes', 'nullable', 'integer', 'min:0'],
            'price'       => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'amenities'   => ['sometimes', 'nullable', 'array'],
            'amenities.*' => ['string'],
        ]);

        $roomType->update($data);

        return response()->json($roomType->load(['hotel', 'rooms']));
    }

    public function destroy(RoomType $roomType): JsonResponse
    {
        $roomType->delete();

        return response()->json(null, 204);
    }
}
