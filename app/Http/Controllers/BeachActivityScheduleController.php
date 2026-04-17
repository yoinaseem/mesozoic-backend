<?php

namespace App\Http\Controllers;

use App\Http\Resources\BeachActivityScheduleResource;
use App\Models\BeachActivity;
use App\Models\BeachActivitySchedule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BeachActivityScheduleController extends Controller
{
    use AuthorizesRequests;

    public function index(BeachActivity $beachActivity): AnonymousResourceCollection
    {
        $schedules = $beachActivity->schedules()->paginate(15);

        return BeachActivityScheduleResource::collection($schedules);
    }

    public function show(BeachActivity $beachActivity, BeachActivitySchedule $schedule): BeachActivityScheduleResource
    {
        return new BeachActivityScheduleResource($schedule);
    }

    public function store(Request $request, BeachActivity $beachActivity): JsonResponse
    {
        $this->authorize('create', BeachActivitySchedule::class);

        $data = $request->validate([
            'activity_date' => ['required', 'date'],
            'start_time' => [
                'required',
                'date_format:H:i:s',
                Rule::unique('beach_activity_schedules', 'start_time')->where(function ($query) use ($beachActivity, $request) {
                    return $query
                        ->where('beach_activity_id', $beachActivity->id)
                        ->whereDate('activity_date', $request->input('activity_date'));
                }),
            ],
            'status' => ['sometimes', Rule::in([
                BeachActivitySchedule::STATUS_PENDING,
                BeachActivitySchedule::STATUS_CONFIRMED,
                BeachActivitySchedule::STATUS_CANCELLED,
            ])],
        ]);

        $schedule = $beachActivity->schedules()->create($data);

        return (new BeachActivityScheduleResource($schedule))->response()->setStatusCode(201);
    }

    public function update(
        Request $request,
        BeachActivity $beachActivity,
        BeachActivitySchedule $schedule
    ): BeachActivityScheduleResource {
        $this->authorize('update', $schedule);

        $activityDate = $request->input('activity_date', $schedule->activity_date?->format('Y-m-d'));

        $data = $request->validate([
            'activity_date' => ['sometimes', 'date'],
            'start_time' => [
                'sometimes',
                'date_format:H:i:s',
                Rule::unique('beach_activity_schedules', 'start_time')
                    ->ignore($schedule->id)
                    ->where(function ($query) use ($beachActivity, $activityDate) {
                        return $query
                            ->where('beach_activity_id', $beachActivity->id)
                            ->whereDate('activity_date', $activityDate);
                    }),
            ],
            'status' => ['sometimes', Rule::in([
                BeachActivitySchedule::STATUS_PENDING,
                BeachActivitySchedule::STATUS_CONFIRMED,
                BeachActivitySchedule::STATUS_CANCELLED,
            ])],
        ]);

        $schedule->update($data);

        return new BeachActivityScheduleResource($schedule);
    }

    public function destroy(BeachActivity $beachActivity, BeachActivitySchedule $schedule): JsonResponse
    {
        $this->authorize('delete', $schedule);

        $schedule->delete();

        return response()->json(null, 204);
    }
}
