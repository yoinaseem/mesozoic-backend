<?php

namespace App\Http\Controllers;

use App\Http\Resources\FerryTypeResource;
use App\Models\FerryType;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FerryTypeController extends Controller
{
    use AuthorizesRequests;

    public function index(): AnonymousResourceCollection
    {
        return FerryTypeResource::collection(FerryType::paginate(15));
    }

    public function show(FerryType $ferryType): FerryTypeResource
    {
        $ferryType->load('ferries')->loadCount('ferries');

        return new FerryTypeResource($ferryType);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', FerryType::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        $ferryType = FerryType::create($data);

        return (new FerryTypeResource($ferryType))->response()->setStatusCode(201);
    }

    public function update(Request $request, FerryType $ferryType): FerryTypeResource
    {
        $this->authorize('update', $ferryType);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'image' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'price' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $ferryType->update($data);

        return new FerryTypeResource($ferryType);
    }

    public function destroy(FerryType $ferryType): JsonResponse
    {
        $this->authorize('delete', $ferryType);

        $ferryType->delete();

        return response()->json(null, 204);
    }
}
