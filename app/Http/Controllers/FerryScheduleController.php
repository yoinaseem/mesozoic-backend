<?php

namespace App\Http\Controllers;

use App\Models\Ferry;
use App\Models\FerrySchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FerryScheduleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            FerrySchedule::query()->with('ferry')->paginate(15)
        );
    }

    public function indexForFerry(Ferry $ferry): JsonResponse
    {
        return response()->json($ferry->schedules()->paginate(15));
    }

    public function show(FerrySchedule $ferrySchedule): JsonResponse
    {
        $ferrySchedule->load('ferry');

        return response()->json($ferrySchedule);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ferry_id' => ['required', 'exists:ferries,id'],
            'travel_date' => ['required', 'date'],
            'departure_time' => [
                'required',
                'date_format:H:i:s',
                $this->uniqueDepartureRule($request),
            ],
            'arrival_time' => ['required', 'date_format:H:i:s', 'after:departure_time'],
            'departure_port' => ['required', 'string', 'max:255'],
            'arrival_port' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['scheduled', 'completed', 'cancelled'])],
        ]);

        if (! array_key_exists('status', $data)) {
            $data['status'] = 'scheduled';
        }

        $schedule = FerrySchedule::create($data);

        return response()->json($schedule->load('ferry'), 201);
    }

    public function update(Request $request, FerrySchedule $ferrySchedule): JsonResponse
    {
        $ferryId = $request->input('ferry_id', $ferrySchedule->ferry_id);
        $travelDate = $request->input('travel_date', $ferrySchedule->travel_date?->format('Y-m-d'));
        $departureTime = $request->input('departure_time', $ferrySchedule->departure_time);

        $request->merge([
            'ferry_id' => $ferryId,
            'travel_date' => $travelDate,
            'departure_time' => $departureTime,
        ]);

        $data = $request->validate([
            'ferry_id' => ['sometimes', 'exists:ferries,id'],
            'travel_date' => ['sometimes', 'date'],
            'departure_time' => [
                'sometimes',
                'date_format:H:i:s',
                $this->uniqueDepartureRule($request, $ferrySchedule->id),
            ],
            'arrival_time' => ['sometimes', 'date_format:H:i:s', 'after:departure_time'],
            'departure_port' => ['sometimes', 'string', 'max:255'],
            'arrival_port' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['scheduled', 'completed', 'cancelled'])],
        ]);

        $ferrySchedule->update($data);

        return response()->json($ferrySchedule->load('ferry'));
    }

    public function destroy(FerrySchedule $ferrySchedule): JsonResponse
    {
        $ferrySchedule->delete();

        return response()->json(null, 204);
    }

    private function uniqueDepartureRule(Request $request, ?int $ignoreId = null): \Illuminate\Validation\Rules\Unique
    {
        $rule = Rule::unique('ferry_schedules', 'departure_time')->where(function ($query) use ($request) {
            return $query
                ->where('ferry_id', $request->input('ferry_id'))
                ->whereDate('travel_date', $request->input('travel_date'));
        });

        if ($ignoreId !== null) {
            $rule->ignore($ignoreId);
        }

        return $rule;
    }
}