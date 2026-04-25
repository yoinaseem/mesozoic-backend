<?php

namespace App\Http\Controllers;

use App\Http\Resources\ThemeParkResource;
use App\Models\ParkActivityBooking;
use App\Models\ParkBooking;
use App\Models\ThemePark;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ThemeParkController extends Controller
{
    use AuthorizesRequests;

    public function index(): AnonymousResourceCollection
    {
        return ThemeParkResource::collection(ThemePark::paginate(10));
    }

    public function show(ThemePark $themePark): ThemeParkResource
    {
        $themePark->load([
            'openingHours',
            'parkActivities' => fn ($q) => $q->withCount('schedules'),
        ]);

        return new ThemeParkResource($themePark);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ThemePark::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string', 'max:2048'],
            'description' => ['required', 'string'],
            'capacity' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:50'],
        ]);

        $themePark = ThemePark::create($data);

        return (new ThemeParkResource($themePark))->response()->setStatusCode(201);
    }

    public function update(Request $request, ThemePark $themePark): ThemeParkResource
    {
        $this->authorize('update', $themePark);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'images' => ['sometimes', 'nullable', 'array'],
            'images.*' => ['string', 'max:2048'],
            'description' => ['sometimes', 'string'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'contact_email' => ['sometimes', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'string', 'max:50'],
        ]);

        // Block capacity-lowering when child activities still claim a higher
        // max_capacity. Per-activity caps must be ≤ park.capacity (validated
        // on activity CRUD); this is the symmetric guard for the park side.
        if (array_key_exists('capacity', $data) && $data['capacity'] < $themePark->capacity) {
            $offenders = $themePark->parkActivities()
                ->where('max_capacity', '>', $data['capacity'])
                ->get(['id', 'name', 'max_capacity']);

            if ($offenders->isNotEmpty()) {
                abort(response()->json([
                    'message' => "Cannot lower park capacity to {$data['capacity']} — some activities have a higher max_capacity. Lower those first.",
                    'offending_activities' => $offenders->map(fn ($a) => [
                        'id' => $a->id,
                        'name' => $a->name,
                        'max_capacity' => $a->max_capacity,
                    ])->values(),
                ], 409));
            }
        }

        $themePark->update($data);

        return new ThemeParkResource($themePark);
    }

    public function destroy(ThemePark $themePark): JsonResponse
    {
        $this->authorize('delete', $themePark);

        // Two paths can block: a direct day-pass booking on this park, OR an
        // activity booking on a schedule nested under this park.
        $blockingPark = ParkBooking::query()
            ->upcomingActive()
            ->where('park_id', $themePark->id)
            ->count();

        $blockingActivity = ParkActivityBooking::query()
            ->upcomingActive()
            ->whereHas('schedule.parkActivity', fn ($q) => $q->where('park_id', $themePark->id))
            ->count();

        $blocking = $blockingPark + $blockingActivity;

        if ($blocking > 0) {
            return response()->json([
                'message'           => 'Cannot archive a theme park with upcoming or in-progress bookings.',
                'blocking_bookings' => $blocking,
            ], 409);
        }

        $themePark->delete();

        return response()->json(null, 204);
    }

    public function restore(ThemePark $themePark): ThemeParkResource
    {
        $this->authorize('restore', $themePark);

        if ($themePark->trashed()) {
            $themePark->restore();
        }

        return new ThemeParkResource($themePark);
    }
}
