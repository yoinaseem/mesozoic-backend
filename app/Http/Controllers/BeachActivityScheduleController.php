<?php

namespace App\Http\Controllers;

use App\Http\Resources\BeachActivityScheduleResource;
use App\Models\BeachActivity;
use App\Models\BeachActivitySchedule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BeachActivityScheduleController extends Controller
{
    use AuthorizesRequests;

    public function index(BeachActivity $beachActivity): AnonymousResourceCollection
    {
        $schedules = $beachActivity->schedules()->paginate(10);

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
            'activity_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'status' => ['sometimes', Rule::in([
                BeachActivitySchedule::STATUS_PENDING,
                BeachActivitySchedule::STATUS_CONFIRMED,
                BeachActivitySchedule::STATUS_CANCELLED,
            ])],
        ]);

        return DB::transaction(function () use ($beachActivity, $data) {
            $activity = BeachActivity::query()
                ->whereKey($beachActivity->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Slot-uniqueness pulled out of validate() into the locked block
            // so concurrent identical creates can't race past the closure.
            // The partial unique index from 2026_04_25_150000 is the DB-level
            // backstop; this check produces the friendlier 422 error.
            $duplicate = $activity->schedules()
                ->whereDate('activity_date', $data['activity_date'])
                ->where('start_time', $data['start_time'])
                ->where('status', '!=', BeachActivitySchedule::STATUS_CANCELLED)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'start_time' => 'A schedule already exists at this date and time.',
                ]);
            }

            $schedule = $activity->schedules()->create($data);

            return (new BeachActivityScheduleResource($schedule))->response()->setStatusCode(201);
        });
    }

    public function update(
        Request $request,
        BeachActivity $beachActivity,
        BeachActivitySchedule $schedule
    ): BeachActivityScheduleResource {
        $this->authorize('update', $schedule);

        $data = $request->validate([
            'activity_date' => ['sometimes', 'date'],
            'start_time' => ['sometimes', 'date_format:H:i:s'],
            'status' => ['sometimes', Rule::in([
                BeachActivitySchedule::STATUS_PENDING,
                BeachActivitySchedule::STATUS_CONFIRMED,
                BeachActivitySchedule::STATUS_CANCELLED,
            ])],
        ]);

        // Past-date guard: only blocks moving the activity_date itself to a
        // past value. Status / future fields on past schedules remain editable
        // for cleanup.
        if (array_key_exists('activity_date', $data) && $data['activity_date'] < now()->toDateString()) {
            throw ValidationException::withMessages([
                'activity_date' => ['Cannot move a schedule to a past date.'],
            ]);
        }

        return DB::transaction(function () use ($beachActivity, $schedule, $data) {
            $activity = BeachActivity::query()
                ->whereKey($beachActivity->id)
                ->lockForUpdate()
                ->firstOrFail();

            $effectiveDate = $data['activity_date'] ?? $schedule->activity_date?->format('Y-m-d');
            $effectiveStartTime = $data['start_time'] ?? $schedule->start_time;

            $duplicate = $activity->schedules()
                ->whereDate('activity_date', $effectiveDate)
                ->where('start_time', $effectiveStartTime)
                ->where('id', '!=', $schedule->id)
                ->where('status', '!=', BeachActivitySchedule::STATUS_CANCELLED)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'start_time' => 'A schedule already exists at this date and time.',
                ]);
            }

            $schedule->update($data);

            return new BeachActivityScheduleResource($schedule);
        });
    }

    public function destroy(BeachActivity $beachActivity, BeachActivitySchedule $schedule): JsonResponse
    {
        $this->authorize('delete', $schedule);

        $schedule->delete();

        return response()->json(null, 204);
    }
}
