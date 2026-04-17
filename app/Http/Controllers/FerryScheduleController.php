<?php

namespace App\Http\Controllers;

use App\Models\Ferry;
use App\Models\FerrySchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

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
                $this->uniqueDepartureRule($request->input('ferry_id'), $request->input('travel_date')),
            ],
            'arrival_date' => ['required', 'date', 'after_or_equal:travel_date'],
            'arrival_time' => ['required', 'date_format:H:i:s'],
            'departure_port' => ['required', 'string', 'max:255'],
            'arrival_port' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['scheduled', 'completed', 'cancelled'])],
        ]);

        $this->validateArrivalAfterDeparture($data);

        $schedule = FerrySchedule::create($data);

        return response()->json($schedule->load('ferry'), 201);
    }

    public function update(Request $request, FerrySchedule $ferrySchedule): JsonResponse
    {
        $ferryId = $request->input('ferry_id', $ferrySchedule->ferry_id);
        $travelDate = $request->input('travel_date', $ferrySchedule->travel_date?->format('Y-m-d'));

        $data = $request->validate([
            'ferry_id' => ['sometimes', 'exists:ferries,id'],
            'travel_date' => ['sometimes', 'date'],
            'departure_time' => [
                'sometimes',
                'date_format:H:i:s',
                $this->uniqueDepartureRule($ferryId, $travelDate, $ferrySchedule->id),
            ],
            'arrival_date' => ['sometimes', 'date', 'after_or_equal:travel_date'],
            'arrival_time' => ['sometimes', 'date_format:H:i:s'],
            'departure_port' => ['sometimes', 'string', 'max:255'],
            'arrival_port' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['scheduled', 'completed', 'cancelled'])],
        ]);

        $this->validateArrivalAfterDeparture(array_merge([
            'travel_date'    => $ferrySchedule->travel_date?->format('Y-m-d'),
            'departure_time' => $ferrySchedule->departure_time,
            'arrival_date'   => $ferrySchedule->arrival_date?->format('Y-m-d'),
            'arrival_time'   => $ferrySchedule->arrival_time,
        ], $data));

        $ferrySchedule->update($data);

        return response()->json($ferrySchedule->load('ferry'));
    }

    public function destroy(FerrySchedule $ferrySchedule): JsonResponse
    {
        $ferrySchedule->delete();

        return response()->json(null, 204);
    }

    /**
     * Ensure arrival datetime is after departure datetime.
     * Works correctly for overnight and multi-day schedules.
     */
    private function validateArrivalAfterDeparture(array $data): void
    {
        $departure = strtotime($data['travel_date'] . ' ' . $data['departure_time']);
        $arrival = strtotime($data['arrival_date'] . ' ' . $data['arrival_time']);

        if ($arrival !== false && $departure !== false && $arrival <= $departure) {
            throw ValidationException::withMessages([
                'arrival_time' => ['The arrival datetime must be after the departure datetime.'],
            ]);
        }
    }

    private function uniqueDepartureRule(mixed $ferryId, mixed $travelDate, ?int $ignoreId = null): Unique
    {
        $rule = Rule::unique('ferry_schedules', 'departure_time')
            ->where(fn ($query) => $query->where('ferry_id', $ferryId)->whereDate('travel_date', $travelDate));

        return $ignoreId ? $rule->ignore($ignoreId) : $rule;
    }
}
