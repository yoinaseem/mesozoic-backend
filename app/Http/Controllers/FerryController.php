<?php

namespace App\Http\Controllers;

use App\Http\Resources\FerryResource;
use App\Models\Ferry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class FerryController extends Controller
{
    use AuthorizesRequests;

    public function index(): AnonymousResourceCollection
    {
        return FerryResource::collection(
            Ferry::with('ferryType')->paginate(10)
        );
    }

    public function show(Ferry $ferry): FerryResource
    {
        $ferry->load(['ferryType', 'schedules'])->loadCount('schedules');

        return new FerryResource($ferry);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Ferry::class);

        $ferryTypeId = $request->input('ferry_type_id');

        $data = $request->validate([
            'ferry_type_id' => ['required', Rule::exists('ferry_types', 'id')],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('ferries')->where(fn ($q) => $q->where('ferry_type_id', $ferryTypeId)),
            ],
        ]);

        $ferry = Ferry::create($data);

        return (new FerryResource($ferry->load('ferryType')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Ferry $ferry): FerryResource
    {
        $this->authorize('update', $ferry);

        $ferryTypeId = $request->input('ferry_type_id', $ferry->ferry_type_id);

        $data = $request->validate([
            'ferry_type_id' => ['sometimes', Rule::exists('ferry_types', 'id')],
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('ferries')
                    ->where(fn ($q) => $q->where('ferry_type_id', $ferryTypeId))
                    ->ignore($ferry->id),
            ],
        ]);

        $ferry->update($data);

        return new FerryResource($ferry->load('ferryType'));
    }

    public function destroy(Ferry $ferry): JsonResponse
    {
        $this->authorize('delete', $ferry);

        $ferry->delete();

        return response()->json(null, 204);
    }
}
