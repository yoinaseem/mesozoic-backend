<?php

namespace App\Http\Controllers;

use App\Http\Resources\FerryResource;
use App\Models\Ferry;
use App\Models\FerryBooking;
use App\Models\FerrySchedule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function destroy(Request $request, Ferry $ferry): JsonResponse
    {
        $this->authorize('delete', $ferry);

        $onConflict = $request->input('on_conflict', 'reject');
        if (! in_array($onConflict, ['reject', 'cascade'], true)) {
            throw ValidationException::withMessages([
                'on_conflict' => ['Invalid on_conflict mode.'],
            ]);
        }

        $blocking = FerryBooking::query()
            ->where('status', 'confirmed')
            ->whereHas('schedule', fn ($q) => $q->where('ferry_id', $ferry->id))
            ->count();

        if ($blocking > 0 && $onConflict !== 'cascade') {
            return response()->json([
                'message' => 'Cannot archive a ferry with confirmed bookings on its slots. Re-send with on_conflict=cascade to cancel them.',
                'blocking_bookings' => $blocking,
            ], 409);
        }

        $cascade = DB::transaction(function () use ($ferry) {
            // Lock all affected slot rows first so a concurrent
            // FerryBookingController::store (which lockForUpdates an individual
            // slot row) can't insert a confirmed booking between our cancel and
            // delete steps and survive on an archived slot/ferry.
            $slotIds = FerrySchedule::query()
                ->where('ferry_id', $ferry->id)
                ->lockForUpdate()
                ->pluck('id');

            $bookingsCancelled = FerryBooking::query()
                ->whereIn('ferry_schedule_id', $slotIds)
                ->where('status', 'confirmed')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            FerrySchedule::query()->whereIn('id', $slotIds)->delete();

            $ferry->delete();

            return [
                'slots_archived' => $slotIds->count(),
                'bookings_cancelled' => $bookingsCancelled,
            ];
        });

        if ($cascade['bookings_cancelled'] === 0 && $cascade['slots_archived'] === 0) {
            return response()->json(null, 204);
        }

        return response()->json(['cascade' => $cascade]);
    }
}
