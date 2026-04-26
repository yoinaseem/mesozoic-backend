<?php

namespace App\Http\Controllers;

use App\Http\Resources\FerryScheduleResource;
use App\Models\Ferry;
use App\Models\FerrySchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

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
        $ferryId = $request->input('ferry_id');

        $data = $request->validate([
            'ferry_id' => ['required', 'exists:ferries,id'],
            'departure_time' => [
                'required',
                'date_format:H:i:s',
                Rule::unique('ferry_schedules')->where(fn ($q) => $q->where('ferry_id', $ferryId)),
            ],
            'arrival_time' => ['required', 'date_format:H:i:s', 'different:departure_time'],
            'departure_port' => ['required', 'string', 'max:255'],
            'arrival_port' => ['required', 'string', 'max:255'],
        ]);

        $schedule = FerrySchedule::create($data);

        return (new FerryScheduleResource($schedule->load('ferry')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, FerrySchedule $ferrySchedule): FerryScheduleResource
    {
        $ferryId = $request->input('ferry_id', $ferrySchedule->ferry_id);

        $data = $request->validate([
            'ferry_id' => ['sometimes', 'exists:ferries,id'],
            'departure_time' => [
                'sometimes',
                'date_format:H:i:s',
                Rule::unique('ferry_schedules')
                    ->where(fn ($q) => $q->where('ferry_id', $ferryId))
                    ->ignore($ferrySchedule->id),
            ],
            'arrival_time' => ['sometimes', 'date_format:H:i:s'],
            'departure_port' => ['sometimes', 'string', 'max:255'],
            'arrival_port' => ['sometimes', 'string', 'max:255'],
        ]);

        $effectiveDeparture = $data['departure_time'] ?? $ferrySchedule->departure_time;
        $effectiveArrival = $data['arrival_time'] ?? $ferrySchedule->arrival_time;
        if ($effectiveDeparture === $effectiveArrival) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'arrival_time' => ['The arrival time must be different from the departure time.'],
            ]);
        }

        $ferrySchedule->update($data);

        return new FerryScheduleResource($ferrySchedule->load('ferry'));
    }

    public function destroy(FerrySchedule $ferrySchedule): JsonResponse
    {
        $ferrySchedule->delete();

        return response()->json(null, 204);
    }
}
