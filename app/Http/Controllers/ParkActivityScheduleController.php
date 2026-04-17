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

        return ParkActivityScheduleResource::collection($schedules);
    }

    public function show(
        ThemePark $themePark,
        ParkActivity $parkActivity,
        ParkActivitySchedule $schedule
    ): ParkActivityScheduleResource {
        return new ParkActivityScheduleResource($schedule);
    }

    public function store(Request $request, ThemePark $themePark, ParkActivity $parkActivity): JsonResponse
    {
        $this->authorize('create', ParkActivitySchedule::class);

        $data = $request->validate([
            'scheduled_date' => ['required', 'date'],
            'scheduled_time' => [
                'required',
                'date_format:H:i:s',
                Rule::unique('park_activity_schedules', 'scheduled_time')->where(function ($query) use ($parkActivity, $request) {
                    return $query
                        ->where('park_activity_id', $parkActivity->id)
                        ->whereDate('scheduled_date', $request->input('scheduled_date'));
                }),
            ],
            'status' => ['sometimes', Rule::in([
                ParkActivitySchedule::STATUS_SCHEDULED,
                ParkActivitySchedule::STATUS_CANCELLED,
                ParkActivitySchedule::STATUS_COMPLETED,
            ])],
            'notes' => ['nullable', 'string'],
        ]);

        $schedule = $parkActivity->schedules()->create($data);

        return (new ParkActivityScheduleResource($schedule))->response()->setStatusCode(201);
    }

    public function update(
        Request $request,
        ThemePark $themePark,
        ParkActivity $parkActivity,
        ParkActivitySchedule $schedule
    ): ParkActivityScheduleResource {
        $this->authorize('update', $schedule);

        $scheduledDate = $request->input('scheduled_date', $schedule->scheduled_date?->format('Y-m-d'));

        $data = $request->validate([
            'scheduled_date' => ['sometimes', 'date'],
            'scheduled_time' => [
                'sometimes',
                'date_format:H:i:s',
                Rule::unique('park_activity_schedules', 'scheduled_time')
                    ->ignore($schedule->id)
                    ->where(function ($query) use ($parkActivity, $scheduledDate) {
                        return $query
                            ->where('park_activity_id', $parkActivity->id)
                            ->whereDate('scheduled_date', $scheduledDate);
                    }),
            ],
            'status' => ['sometimes', Rule::in([
                ParkActivitySchedule::STATUS_SCHEDULED,
                ParkActivitySchedule::STATUS_CANCELLED,
                ParkActivitySchedule::STATUS_COMPLETED,
            ])],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $schedule->update($data);

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
