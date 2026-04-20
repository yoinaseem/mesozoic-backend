<?php

namespace App\Http\Controllers;

use App\Http\Resources\FerryResource;
use App\Models\Ferry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FerryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FerryResource::collection(Ferry::paginate(15));
    }

    public function show(Ferry $ferry): FerryResource
    {
        $ferry->load('schedules')->loadCount('schedules');

        return new FerryResource($ferry);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'capacity' => ['required', 'integer', 'min:1'],
            'image' => ['nullable', 'string', 'max:255'],
        ]);

        $ferry = Ferry::create($data);

        return (new FerryResource($ferry))->response()->setStatusCode(201);
    }

    public function update(Request $request, Ferry $ferry): FerryResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'image' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $ferry->update($data);

        return new FerryResource($ferry);
    }

    public function destroy(Ferry $ferry): JsonResponse
    {
        $ferry->delete();

        return response()->json(null, 204);
    }
}
