<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HotelController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Hotel::paginate(15));
    }

    public function show(Hotel $hotel): JsonResponse
    {
        $hotel->load(['rooms', 'roomTypes']);

        return response()->json($hotel);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'address'     => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'amenities'   => ['nullable', 'array'],
            'amenities.*' => ['string'],
            'image'       => ['nullable', 'string', 'max:255'],
        ]);

        $hotel = Hotel::create($data);

        return response()->json($hotel, 201);
    }

    public function update(Request $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'address'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'amenities'   => ['sometimes', 'nullable', 'array'],
            'amenities.*' => ['string'],
            'image'       => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $hotel->update($data);

        return response()->json($hotel);
    }

    public function destroy(Hotel $hotel): JsonResponse
    {
        $hotel->delete();

        return response()->json(null, 204);
    }
}
