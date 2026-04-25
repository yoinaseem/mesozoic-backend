<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Collection;

/**
 * Thrown inside an opening-hour or override mutation transaction when the
 * resulting hours invalidate one or more pre-existing schedules and the
 * caller did not pass on_conflict=cascade. Triggers transaction rollback
 * (so the hour change is not persisted) and is caught at the controller
 * layer to render a 409 conflict report.
 *
 * @see ParkScheduleReconciler::findScheduleConflicts()
 */
class HoursCascadeConflictException extends Exception
{
    public function __construct(public Collection $conflicts)
    {
        parent::__construct('Hours change conflicts with existing schedules.');
    }

    /**
     * Render the conflict report payload for the 409 response.
     */
    public function payload(): array
    {
        return [
            'message' => $this->getMessage(),
            'conflicts' => $this->conflicts->map(fn (array $c) => [
                'schedule_id' => $c['schedule']->id,
                'park_activity_id' => $c['schedule']->park_activity_id,
                'date' => $c['schedule']->date->toDateString(),
                'start_time' => $c['schedule']->start_time,
                'end_time' => $c['schedule']->end_time,
                'confirmed_bookings' => $c['confirmed_bookings'],
            ])->values()->all(),
            'counts' => [
                'schedules' => $this->conflicts->count(),
                'bookings' => (int) $this->conflicts->sum('confirmed_bookings'),
            ],
        ];
    }
}
