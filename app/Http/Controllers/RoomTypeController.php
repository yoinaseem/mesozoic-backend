<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoomTypeResource;
use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoomTypeController extends Controller
{
    use AuthorizesRequests;

    public function index(Hotel $hotel): AnonymousResourceCollection
    {
        $roomTypes = $hotel->roomTypes()->withCount('rooms')->paginate(10);

        return RoomTypeResource::collection($roomTypes);
    }

    public function show(Hotel $hotel, RoomType $roomType): RoomTypeResource
    {
        $roomType->loadCount('rooms');

        return new RoomTypeResource($roomType);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        // Pass the parent hotel to the policy so it can check the pivot.
        $this->authorize('create', [RoomType::class, $hotel]);

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
        $roomType->rooms_count = 0;

        return (new RoomTypeResource($roomType))->response()->setStatusCode(201);
    }

    public function update(Request $request, Hotel $hotel, RoomType $roomType): RoomTypeResource
    {
        $this->authorize('update', $roomType);

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
        $roomType->loadCount('rooms');

        return new RoomTypeResource($roomType);
    }

    public function destroy(Hotel $hotel, RoomType $roomType): JsonResponse
    {
        $this->authorize('delete', $roomType);

        $roomType->delete();

        return response()->json(null, 204);
    }
}
