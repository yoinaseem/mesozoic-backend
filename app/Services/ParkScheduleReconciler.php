<?php

namespace App\Services;

use App\Models\ParkActivity;
use App\Models\ParkActivityBooking;
use App\Models\ParkActivitySchedule;
use App\Models\ThemePark;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared park-schedule reconciliation logic. Used by:
 *   - schedule CRUD (validate window fits hours, no overlap)
 *   - hours mutations (find conflicts, cascade-cancel on confirm)
 *   - booking flow for is_all_day activities (lazy materialization, lands in
 *     a later commit of DESD-95)
 *
 * All window math is date-aware so overnight windows work transparently:
 *   - schedule end < start ⇒ end is on date+1
 *   - park close < open    ⇒ close is on date+1
 * The schedule's `date` is treated as the park-opening date even when the
 * schedule itself crosses midnight.
 */
class ParkScheduleReconciler
{
    /**
     * Throw a ValidationException if [start, end) does not fit inside the
     * effective hours for $date. Treats not_configured/closed as fail-closed.
     */
    public function validateWindow(ThemePark $park, string $date, string $start, string $end): void
    {
        $hours = $park->effectiveHoursOn($date);

        if ($hours['status'] !== 'open') {
            throw ValidationException::withMessages([
                'date' => ['The park is not open on this date.'],
            ]);
        }

        [$schedStart, $schedEnd] = $this->normalizeWindow($date, $start, $end);
        [$openDt, $closeDt] = $this->normalizeWindow($date, $hours['open_time'], $hours['close_time']);

        if ($schedStart->lessThan($openDt) || $schedEnd->greaterThan($closeDt)) {
            throw ValidationException::withMessages([
                'start_time' => ["The schedule must fall within the park's open hours for this date."],
            ]);
        }
    }

    /**
     * Returns the first live (non-trashed, non-cancelled) schedule of $activity
     * whose [start, end) overlaps the proposed window, or null if none. Skips
     * is_all_day activities — those use per-date uniqueness rather than
     * overlap.
     */
    public function findOverlappingSchedule(
        ParkActivity $activity,
        string $date,
        string $start,
        string $end,
        ?int $ignoreId = null,
    ): ?ParkActivitySchedule {
        if ($activity->is_all_day) {
            return null;
        }

        [$newStart, $newEnd] = $this->normalizeWindow($date, $start, $end);

        // Pull candidates on this date and adjacent days so an overnight
        // candidate stored under D-1 still gets considered against a D
        // schedule.
        $candidates = $activity->schedules()
            ->whereDate('date', '>=', $newStart->subDay()->toDateString())
            ->whereDate('date', '<=', $newEnd->addDay()->toDateString())
            ->where('status', '!=', ParkActivitySchedule::STATUS_CANCELLED)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get();

        foreach ($candidates as $candidate) {
            [$cStart, $cEnd] = $this->normalizeWindow(
                $candidate->date->toDateString(),
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
     * For a date range, return the live schedules of $park whose stored
     * [start, end) no longer fits effective hours, paired with their confirmed
     * activity-booking count. Past-dated schedules are skipped — we never
     * rewrite history.
     *
     * @return Collection<int, array{schedule: ParkActivitySchedule, confirmed_bookings: int}>
     */
    public function findScheduleConflicts(ThemePark $park, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $today = today()->toDateString();
        $rangeStart = $from->toDateString();
        $rangeEnd = $to->toDateString();

        $effectiveStart = $rangeStart < $today ? $today : $rangeStart;
        if ($effectiveStart > $rangeEnd) {
            return collect();
        }

        $schedules = ParkActivitySchedule::query()
            ->whereHas('parkActivity', fn ($q) => $q->where('park_id', $park->id))
            ->whereDate('date', '>=', $effectiveStart)
            ->whereDate('date', '<=', $rangeEnd)
            ->where('status', '!=', ParkActivitySchedule::STATUS_CANCELLED)
            ->with('parkActivity')
            ->get();

        $conflicts = collect();

        foreach ($schedules as $schedule) {
            try {
                $this->validateWindow(
                    $park,
                    $schedule->date->toDateString(),
                    $schedule->start_time,
                    $schedule->end_time,
                );
                continue;
            } catch (ValidationException $e) {
                $confirmedBookings = ParkActivityBooking::query()
                    ->where('park_activity_schedule_id', $schedule->id)
                    ->where('status', 'confirmed')
                    ->count();

                $conflicts->push([
                    'schedule' => $schedule,
                    'confirmed_bookings' => $confirmedBookings,
                ]);
            }
        }

        return $conflicts;
    }

    /**
     * Bulk-cancel the conflicting schedules and their confirmed bookings in
     * one transaction. Appends `[cascade] $reason` to schedule notes for
     * audit. Returns counts of what was actually flipped.
     *
     * @param  Collection<int, array{schedule: ParkActivitySchedule, confirmed_bookings: int}>  $conflicts
     * @return array{schedules_cancelled: int, bookings_cancelled: int}
     */
    public function cascadeCancel(Collection $conflicts, string $reason): array
    {
        if ($conflicts->isEmpty()) {
            return ['schedules_cancelled' => 0, 'bookings_cancelled' => 0];
        }

        return DB::transaction(function () use ($conflicts, $reason) {
            $scheduleIds = $conflicts->pluck('schedule.id')->all();
            $now = now();

            $bookingsCancelled = ParkActivityBooking::query()
                ->whereIn('park_activity_schedule_id', $scheduleIds)
                ->where('status', 'confirmed')
                ->update([
                    'status' => ParkActivityBooking::STATUS_CANCELLED,
                    'cancelled_at' => $now,
                    'updated_at' => $now,
                ]);

            $schedulesCancelled = 0;
            foreach ($conflicts as $conflict) {
                /** @var ParkActivitySchedule $schedule */
                $schedule = $conflict['schedule'];

                if ($schedule->status === ParkActivitySchedule::STATUS_CANCELLED) {
                    continue;
                }

                $auditLine = "[cascade] {$reason}";
                $existing = $schedule->notes;
                $newNotes = $existing !== null && $existing !== ''
                    ? "{$existing}\n\n{$auditLine}"
                    : $auditLine;

                $schedule->update([
                    'status' => ParkActivitySchedule::STATUS_CANCELLED,
                    'notes' => $newNotes,
                ]);
                $schedulesCancelled++;
            }

            return [
                'schedules_cancelled' => $schedulesCancelled,
                'bookings_cancelled' => $bookingsCancelled,
            ];
        });
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
