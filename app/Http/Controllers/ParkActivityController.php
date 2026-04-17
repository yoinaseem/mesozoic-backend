<?php

namespace App\Http\Controllers;

use App\Http\Resources\ParkActivityResource;
use App\Models\ParkActivity;
use App\Models\ThemePark;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ParkActivityController extends Controller
{
    use AuthorizesRequests;

    public function index(ThemePark $themePark): AnonymousResourceCollection
    {
        return ParkActivityResource::collection(
            $themePark->activities()->paginate(15)
        );
    }

    public function show(ThemePark $themePark, ParkActivity $parkActivity): ParkActivityResource
    {
        $parkActivity->load('schedules')->loadCount('schedules');

        return new ParkActivityResource($parkActivity);
    }

    public function store(Request $request, ThemePark $themePark): JsonResponse
    {
        $this->authorize('create', ParkActivity::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'image' => ['nullable', 'string', 'max:255'],
            'duration' => ['required', 'integer', 'min:1'],
            'max_capacity' => ['required', 'integer', 'min:1'],
            'is_all_day' => ['sometimes', 'boolean'],
        ]);

        $parkActivity = $themePark->activities()->create($data);

        return (new ParkActivityResource($parkActivity))->response()->setStatusCode(201);
    }

    public function update(Request $request, ThemePark $themePark, ParkActivity $parkActivity): ParkActivityResource
    {
        $this->authorize('update', $parkActivity);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'image' => ['sometimes', 'nullable', 'string', 'max:255'],
            'duration' => ['sometimes', 'integer', 'min:1'],
            'max_capacity' => ['sometimes', 'integer', 'min:1'],
            'is_all_day' => ['sometimes', 'boolean'],
        ]);

        $parkActivity->update($data);

        return new ParkActivityResource($parkActivity);
    }

    public function destroy(ThemePark $themePark, ParkActivity $parkActivity): JsonResponse
    {
        $this->authorize('delete', $parkActivity);

        $parkActivity->delete();

        return response()->json(null, 204);
    }
}
