<?php

namespace App\Http\Controllers;

use App\Http\Resources\FerryTypeResource;
use App\Models\Ferry;
use App\Models\FerryBooking;
use App\Models\FerrySchedule;
use App\Models\FerryType;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FerryTypeController extends Controller
{
    use AuthorizesRequests;

    public function index(): AnonymousResourceCollection
    {
        return FerryTypeResource::collection(FerryType::paginate(10));
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

    public function destroy(Request $request, FerryType $ferryType): JsonResponse
    {
        $this->authorize('delete', $ferryType);

        $onConflict = $request->input('on_conflict', 'reject');
        if (! in_array($onConflict, ['reject', 'cascade'], true)) {
            throw ValidationException::withMessages([
                'on_conflict' => ['Invalid on_conflict mode.'],
            ]);
        }

        $blocking = FerryBooking::query()
            ->where('status', 'confirmed')
            ->whereHas('schedule.ferry', fn ($q) => $q->where('ferry_type_id', $ferryType->id))
            ->count();

        if ($blocking > 0 && $onConflict !== 'cascade') {
            return response()->json([
                'message' => 'Cannot archive a ferry type with confirmed bookings on its vessels. Re-send with on_conflict=cascade to cancel them.',
                'blocking_bookings' => $blocking,
            ], 409);
        }

        $cascade = DB::transaction(function () use ($ferryType) {
            $ferryIds = $ferryType->ferries()->pluck('id');
            $slotIds = FerrySchedule::query()->whereIn('ferry_id', $ferryIds)->pluck('id');

            $bookingsCancelled = FerryBooking::query()
                ->whereIn('ferry_schedule_id', $slotIds)
                ->where('status', 'confirmed')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            FerrySchedule::query()->whereIn('id', $slotIds)->delete();
            Ferry::query()->whereIn('id', $ferryIds)->delete();

            $ferryType->delete();

            return [
                'ferries_archived' => $ferryIds->count(),
                'slots_archived' => $slotIds->count(),
                'bookings_cancelled' => $bookingsCancelled,
            ];
        });

        if ($cascade['bookings_cancelled'] === 0
            && $cascade['slots_archived'] === 0
            && $cascade['ferries_archived'] === 0) {
            return response()->json(null, 204);
        }

        return response()->json(['cascade' => $cascade]);
    }
}
