<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    public function index(Hotel $hotel): JsonResponse
    {
        $roomTypes = $hotel->roomTypes()->with('rooms')->paginate(15);

        return response()->json($roomTypes);
    }

    public function show(Hotel $hotel, RoomType $roomType): JsonResponse
    {
        $roomType->load('rooms');

        return response()->json($roomType);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image'       => ['nullable', 'string', 'max:255'],
            'capacity'    => ['nullable', 'integer', 'min:1'],
            'price'       => ['nullable', 'numeric', 'min:0'],
            'amenities'   => ['nullable', 'array'],
            'amenities.*' => ['string'],
        ]);

        $roomType = $hotel->roomTypes()->create($data);

        return response()->json($roomType->load('rooms'), 201);
    }

    public function update(Request $request, Hotel $hotel, RoomType $roomType): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'image'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity'    => ['sometimes', 'nullable', 'integer', 'min:1'],
            'price'       => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'amenities'   => ['sometimes', 'nullable', 'array'],
            'amenities.*' => ['string'],
        ]);

        $roomType->update($data);

        return response()->json($roomType->load('rooms'));
    }

    public function destroy(Hotel $hotel, RoomType $roomType): JsonResponse
    {
        $roomType->delete();

        return response()->json(null, 204);
    }
}
