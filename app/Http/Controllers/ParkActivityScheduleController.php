<?php

namespace App\Http\Controllers;

use App\Http\Resources\ParkActivityScheduleResource;
use App\Models\ParkActivity;
use App\Models\ParkActivitySchedule;
use App\Models\ThemePark;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ParkActivityScheduleController extends Controller
{
    use AuthorizesRequests;

    public function index(ThemePark $themePark, ParkActivity $parkActivity): AnonymousResourceCollection
    {
        $schedules = $parkActivity->schedules()->paginate(15);
        $schedules->getCollection()->each->setRelation('parkActivity', $parkActivity);

        return ParkActivityScheduleResource::collection($schedules);
    }

    public function show(
        ThemePark $themePark,
        ParkActivity $parkActivity,
        ParkActivitySchedule $schedule
    ): ParkActivityScheduleResource {
        $schedule->setRelation('parkActivity', $parkActivity);

        return new ParkActivityScheduleResource($schedule);
    }

    public function store(Request $request, ThemePark $themePark, ParkActivity $parkActivity): JsonResponse
    {
        $this->authorize('create', ParkActivitySchedule::class);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'start_time' => [
                'required',
                'date_format:H:i:s',
                function ($attribute, $value, $fail) use ($parkActivity, $request) {
                    $exists = $parkActivity->schedules()
                        ->whereDate('date', $request->input('date'))
                        ->where('start_time', $value)
                        ->exists();
                    if ($exists) {
                        $fail('A schedule already exists at this date and time.');
                    }
                },
            ],
            'end_time' => ['nullable', 'date_format:H:i:s', 'different:start_time'],
            'status' => ['sometimes', Rule::in([
                ParkActivitySchedule::STATUS_SCHEDULED,
                ParkActivitySchedule::STATUS_CANCELLED,
                ParkActivitySchedule::STATUS_COMPLETED,
            ])],
            'notes' => ['nullable', 'string'],
        ]);

        $schedule = $parkActivity->schedules()->create($data);
        $schedule->setRelation('parkActivity', $parkActivity);

        return (new ParkActivityScheduleResource($schedule))->response()->setStatusCode(201);
    }

    public function update(
        Request $request,
        ThemePark $themePark,
        ParkActivity $parkActivity,
        ParkActivitySchedule $schedule
    ): ParkActivityScheduleResource {
        $this->authorize('update', $schedule);

        $effectiveDate = $request->input('date', $schedule->date?->format('Y-m-d'));

        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'start_time' => [
                'sometimes',
                'date_format:H:i:s',
                function ($attribute, $value, $fail) use ($parkActivity, $schedule, $effectiveDate) {
                    $exists = $parkActivity->schedules()
                        ->whereDate('date', $effectiveDate)
                        ->where('start_time', $value)
                        ->where('id', '!=', $schedule->id)
                        ->exists();
                    if ($exists) {
                        $fail('A schedule already exists at this date and time.');
                    }
                },
            ],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i:s'],
            'status' => ['sometimes', Rule::in([
                ParkActivitySchedule::STATUS_SCHEDULED,
                ParkActivitySchedule::STATUS_CANCELLED,
                ParkActivitySchedule::STATUS_COMPLETED,
            ])],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $effectiveStart = array_key_exists('start_time', $data) ? $data['start_time'] : $schedule->start_time;
        $effectiveEnd = array_key_exists('end_time', $data) ? $data['end_time'] : $schedule->end_time;
        if ($effectiveEnd !== null && $effectiveEnd === $effectiveStart) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'end_time' => 'The end time must be different from the start time.',
            ]);
        }

        $schedule->update($data);
        $schedule->setRelation('parkActivity', $parkActivity);

        return new ParkActivityScheduleResource($schedule);
    }

    public function destroy(
        ThemePark $themePark,
        ParkActivity $parkActivity,
        ParkActivitySchedule $schedule
    ): JsonResponse {
        $this->authorize('delete', $schedule);

        $schedule->delete();

        return response()->json(null, 204);
    }
}
