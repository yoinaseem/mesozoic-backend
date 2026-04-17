<?php

namespace App\Http\Controllers;

use App\Http\Resources\BeachActivityResource;
use App\Models\BeachActivity;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BeachActivityController extends Controller
{
    use AuthorizesRequests;

    public function index(): AnonymousResourceCollection
    {
        return BeachActivityResource::collection(BeachActivity::paginate(15));
    }

    public function show(BeachActivity $beachActivity): BeachActivityResource
    {
        $beachActivity->load('schedules')->loadCount('schedules');

        return new BeachActivityResource($beachActivity);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', BeachActivity::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'capacity' => ['required', 'integer', 'min:1'],
            'duration' => ['required', 'integer', 'min:1'],
            'image' => ['nullable', 'string', 'max:255'],
        ]);

        $activity = BeachActivity::create($data);

        return (new BeachActivityResource($activity))->response()->setStatusCode(201);
    }

    public function update(Request $request, BeachActivity $beachActivity): BeachActivityResource
    {
        $this->authorize('update', $beachActivity);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'duration' => ['sometimes', 'integer', 'min:1'],
            'image' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $beachActivity->update($data);

        return new BeachActivityResource($beachActivity);
    }

    public function destroy(BeachActivity $beachActivity): JsonResponse
    {
        $this->authorize('delete', $beachActivity);

        $beachActivity->delete();

        return response()->json(null, 204);
    }
}
