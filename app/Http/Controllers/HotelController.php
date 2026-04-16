<?php

namespace App\Http\Controllers;

use App\Http\Resources\HotelResource;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HotelController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return HotelResource::collection(Hotel::paginate(15));
    }

    public function show(Hotel $hotel): HotelResource
    {
        $hotel->load(['roomTypes' => fn ($q) => $q->withCount('rooms')]);

        return new HotelResource($hotel);
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

        return (new HotelResource($hotel))->response()->setStatusCode(201);
    }

    public function update(Request $request, Hotel $hotel): HotelResource
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

        return new HotelResource($hotel);
    }

    public function destroy(Hotel $hotel): JsonResponse
    {
        $hotel->delete();

        return response()->json(null, 204);
    }
}
