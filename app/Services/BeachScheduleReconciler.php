<?php

namespace App\Services;

use App\Models\BeachActivity;
use App\Models\BeachActivitySchedule;
use Carbon\CarbonImmutable;

/**
 * Beach equivalent of ParkScheduleReconciler. Smaller surface — beach has
 * no opening-hours / overrides model and no is_all_day flag, so this
 * service only needs the overlap-detection method (no validateWindow,
 * findScheduleConflicts, cascadeCancel, or materializeAllDaySchedule).
 *
 * All window math is date-aware so overnight schedules work transparently:
 * if `end < start`, end is interpreted as next-calendar-day. The
 * schedule's `activity_date` is treated as the operating-day even when
 * the schedule itself crosses midnight.
 */
class BeachScheduleReconciler
{
    /**
     * Returns the first live (non-cancelled) schedule of $activity whose
     * `[start, end)` overlaps the proposed window, or null. Half-open
     * intervals: an end-edge touch (e.g. existing 09:00–10:00 vs
     * proposed 10:00–11:00) is not an overlap.
     */
    public function findOverlappingSchedule(
        BeachActivity $activity,
        string $date,
        string $start,
        string $end,
        ?int $ignoreId = null,
    ): ?BeachActivitySchedule {
        [$newStart, $newEnd] = $this->normalizeWindow($date, $start, $end);

        // Pull candidates on this date and adjacent days so an overnight
        // candidate stored under D-1 still gets considered against a D
        // schedule.
        $candidates = $activity->schedules()
            ->whereDate('activity_date', '>=', $newStart->subDay()->toDateString())
            ->whereDate('activity_date', '<=', $newEnd->addDay()->toDateString())
            ->where('status', '!=', BeachActivitySchedule::STATUS_CANCELLED)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get();

        foreach ($candidates as $candidate) {
            [$cStart, $cEnd] = $this->normalizeWindow(
                $candidate->activity_date->toDateString(),
                $candidate->start_time,
                $candidate->end_time,
            );

            // Half-open intervals overlap iff a < d AND c < b
            if ($newStart->lessThan($cEnd) && $cStart->lessThan($newEnd)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Normalize a (date, start_time, end_time) tuple into [startDt, endDt)
     * with overnight wrap: if end ≤ start, end lands on date+1.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function normalizeWindow(string $dateStr, string $start, string $end): array
    {
        $date = CarbonImmutable::parse($dateStr)->startOfDay();
        $startDt = $date->setTimeFromTimeString($start);
        $endDt = $date->setTimeFromTimeString($end);

        if ($endDt->lessThanOrEqualTo($startDt)) {
            $endDt = $endDt->addDay();
        }

        return [$startDt, $endDt];
    }
}
