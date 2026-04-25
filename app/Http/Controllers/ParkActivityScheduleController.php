<?php

namespace App\Http\Controllers;

use App\Http\Resources\ParkActivityScheduleResource;
use App\Models\ParkActivity;
use App\Models\ParkActivityBooking;
use App\Models\ParkActivitySchedule;
use App\Models\ThemePark;
use App\Services\ParkScheduleReconciler;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParkActivityScheduleController extends Controller
{
    use AuthorizesRequests;

    public function index(ThemePark $themePark, ParkActivity $parkActivity): AnonymousResourceCollection
    {
        $schedules = $parkActivity->schedules()->paginate(10);
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

    public function store(
        Request $request,
        ThemePark $themePark,
        ParkActivity $parkActivity,
        ParkScheduleReconciler $reconciler,
    ): JsonResponse {
        $this->authorize('create', ParkActivitySchedule::class);

        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time' => ['required', 'date_format:H:i:s', 'different:start_time'],
            'status' => ['sometimes', Rule::in([
                ParkActivitySchedule::STATUS_SCHEDULED,
                ParkActivitySchedule::STATUS_CANCELLED,
                ParkActivitySchedule::STATUS_COMPLETED,
            ])],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($themePark, $parkActivity, $data, $reconciler) {
            $activity = ParkActivity::query()
                ->whereKey($parkActivity->id)
                ->lockForUpdate()
                ->firstOrFail();

            $reconciler->validateWindow(
                $themePark,
                $data['date'],
                $data['start_time'],
                $data['end_time'],
            );

            $overlap = $reconciler->findOverlappingSchedule(
                $activity,
                $data['date'],
                $data['start_time'],
                $data['end_time'],
            );
            if ($overlap !== null) {
                throw ValidationException::withMessages([
                    'start_time' => [
                        "This schedule overlaps an existing schedule on {$overlap->date->toDateString()} ({$overlap->start_time}–{$overlap->end_time}).",
                    ],
                ]);
            }

            $schedule = $activity->schedules()->create($data);
            $schedule->setRelation('parkActivity', $activity);

            return (new ParkActivityScheduleResource($schedule))->response()->setStatusCode(201);
        });
    }

    public function update(
        Request $request,
        ThemePark $themePark,
        ParkActivity $parkActivity,
        ParkActivitySchedule $schedule,
        ParkScheduleReconciler $reconciler,
    ): ParkActivityScheduleResource {
        $this->authorize('update', $schedule);

        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'start_time' => ['sometimes', 'date_format:H:i:s'],
            'end_time' => ['sometimes', 'date_format:H:i:s'],
            'status' => ['sometimes', Rule::in([
                ParkActivitySchedule::STATUS_SCHEDULED,
                ParkActivitySchedule::STATUS_CANCELLED,
                ParkActivitySchedule::STATUS_COMPLETED,
            ])],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if (array_key_exists('date', $data) && $data['date'] < today()->toDateString()) {
            throw ValidationException::withMessages([
                'date' => ['Cannot move a schedule to a past date.'],
            ]);
        }

        $effectiveStart = array_key_exists('start_time', $data) ? $data['start_time'] : $schedule->start_time;
        $effectiveEnd = array_key_exists('end_time', $data) ? $data['end_time'] : $schedule->end_time;
        if ($effectiveEnd === $effectiveStart) {
            throw ValidationException::withMessages([
                'end_time' => 'The end time must be different from the start time.',
            ]);
        }

        $windowChanges = array_key_exists('date', $data)
            || array_key_exists('start_time', $data)
            || array_key_exists('end_time', $data);

        return DB::transaction(function () use ($themePark, $parkActivity, $schedule, $data, $reconciler, $windowChanges, $effectiveStart, $effectiveEnd) {
            $activity = ParkActivity::query()
                ->whereKey($parkActivity->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($windowChanges) {
                $effectiveDate = $data['date'] ?? $schedule->date?->format('Y-m-d');

                $reconciler->validateWindow($themePark, $effectiveDate, $effectiveStart, $effectiveEnd);

                $overlap = $reconciler->findOverlappingSchedule(
                    $activity,
                    $effectiveDate,
                    $effectiveStart,
                    $effectiveEnd,
                    $schedule->id,
                );
                if ($overlap !== null) {
                    throw ValidationException::withMessages([
                        'start_time' => [
                            "This schedule overlaps an existing schedule on {$overlap->date->toDateString()} ({$overlap->start_time}–{$overlap->end_time}).",
                        ],
                    ]);
                }
            }

            $schedule->update($data);
            $schedule->setRelation('parkActivity', $activity);

            return new ParkActivityScheduleResource($schedule);
        });
    }

    public function destroy(
        ThemePark $themePark,
        ParkActivity $parkActivity,
        ParkActivitySchedule $schedule
    ): JsonResponse {
        $this->authorize('delete', $schedule);

        $blocking = ParkActivityBooking::query()
            ->upcomingActive()
            ->where('park_activity_schedule_id', $schedule->id)
            ->count();

        if ($blocking > 0) {
            return response()->json([
                'message'           => 'Cannot archive a schedule with upcoming or in-progress bookings.',
                'blocking_bookings' => $blocking,
            ], 409);
        }

        $schedule->delete();

        return response()->json(null, 204);
    }

    public function restore(
        ThemePark $themePark,
        ParkActivity $parkActivity,
        ParkActivitySchedule $schedule
    ): ParkActivityScheduleResource {
        $this->authorize('restore', $schedule);

        if ($themePark->trashed()) {
            abort(response()->json([
                'message' => 'Restore the parent theme park first.',
            ], 409));
        }

        if ($parkActivity->trashed()) {
            abort(response()->json([
                'message' => 'Restore the parent park activity first.',
            ], 409));
        }

        if ($schedule->trashed()) {
            $schedule->restore();
        }

        $schedule->setRelation('parkActivity', $parkActivity);

        return new ParkActivityScheduleResource($schedule);
    }
}
