<?php

namespace App\Http\Controllers;

use App\Http\Resources\FerryScheduleResource;
use App\Models\Ferry;
use App\Models\FerrySchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FerryScheduleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FerryScheduleResource::collection(
            FerrySchedule::query()->with('ferry')->paginate(10)
        );
    }

    public function indexForFerry(Ferry $ferry): AnonymousResourceCollection
    {
        return FerryScheduleResource::collection($ferry->schedules()->paginate(10));
    }

    public function show(FerrySchedule $ferrySchedule): FerryScheduleResource
    {
        $ferrySchedule->load('ferry');

        return new FerryScheduleResource($ferrySchedule);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ferry_id' => ['required', 'exists:ferries,id'],
            'departure_time' => ['required', 'date_format:H:i:s'],
            'arrival_time' => ['required', 'date_format:H:i:s', 'different:departure_time'],
            'departure_port' => ['required', 'string', 'max:255'],
            'arrival_port' => ['required', 'string', 'max:255'],
        ]);

        $schedule = DB::transaction(function () use ($data) {
            // Lock the parent ferry so two concurrent slot creates can't both
            // pass the slot-uniqueness check below — partial of DESD-95/97.
            Ferry::query()->whereKey($data['ferry_id'])->lockForUpdate()->firstOrFail();

            $exists = FerrySchedule::query()
                ->where('ferry_id', $data['ferry_id'])
                ->where('departure_time', $data['departure_time'])
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'departure_time' => ['A slot at this departure time already exists for this ferry.'],
                ]);
            }

            return FerrySchedule::create($data);
        });

        return (new FerryScheduleResource($schedule->load('ferry')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, FerrySchedule $ferrySchedule): FerryScheduleResource
    {
        $data = $request->validate([
            'ferry_id' => ['sometimes', 'exists:ferries,id'],
            'departure_time' => ['sometimes', 'date_format:H:i:s'],
            'arrival_time' => ['sometimes', 'date_format:H:i:s'],
            'departure_port' => ['sometimes', 'string', 'max:255'],
            'arrival_port' => ['sometimes', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($ferrySchedule, $data) {
            $ferryId = $data['ferry_id'] ?? $ferrySchedule->ferry_id;
            Ferry::query()->whereKey($ferryId)->lockForUpdate()->firstOrFail();

            $effectiveDeparture = $data['departure_time'] ?? $ferrySchedule->departure_time;
            $effectiveArrival = $data['arrival_time'] ?? $ferrySchedule->arrival_time;
            if ($effectiveDeparture === $effectiveArrival) {
                throw ValidationException::withMessages([
                    'arrival_time' => ['The arrival time must be different from the departure time.'],
                ]);
            }

            if (array_key_exists('ferry_id', $data) || array_key_exists('departure_time', $data)) {
                $exists = FerrySchedule::query()
                    ->where('ferry_id', $ferryId)
                    ->where('departure_time', $effectiveDeparture)
                    ->where('id', '!=', $ferrySchedule->id)
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'departure_time' => ['A slot at this departure time already exists for this ferry.'],
                    ]);
                }
            }

            $ferrySchedule->update($data);
        });

        return new FerryScheduleResource($ferrySchedule->load('ferry'));
    }

    public function destroy(FerrySchedule $ferrySchedule): JsonResponse
    {
        $ferrySchedule->delete();

        return response()->json(null, 204);
    }
}
