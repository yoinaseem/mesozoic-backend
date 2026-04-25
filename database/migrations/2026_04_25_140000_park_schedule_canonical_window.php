<?php

use App\Models\ParkActivity;
use App\Models\ParkActivitySchedule;
use App\Models\ThemePark;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Model B: schedule.start_time / end_time are canonical. Backfills end_time
 * for every existing row, then makes the column NOT NULL. Two backfill paths:
 *   - timed activity: end = start + activity.duration minutes (overnight wrap
 *     handled by Carbon::addMinutes which crosses midnight cleanly)
 *   - all-day activity: window mirrors effectiveHoursOn(date) — rows on
 *     closed / not_configured dates flip to status='cancelled' since they
 *     were never resolvable to a concrete window
 * Schedules whose parent activity is missing or whose timed parent has null
 * duration are flipped to status='cancelled' rather than guessed.
 *
 * After this migration, ParkActivity.duration is a UI default only — never
 * consumed at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        // chunkById, NOT chunk: each iteration mutates end_time, so rows leave
        // the result set as we process them. chunk()'s OFFSET-based paging
        // would skip records once the window shifts; chunkById uses
        // `WHERE id > last_seen` which is immune to that.
        ParkActivitySchedule::withTrashed()
            ->whereNull('end_time')
            ->chunkById(200, function ($schedules) {
                foreach ($schedules as $schedule) {
                    $activity = ParkActivity::withTrashed()->find($schedule->park_activity_id);

                    if ($activity === null) {
                        $schedule->status = ParkActivitySchedule::STATUS_CANCELLED;
                        $schedule->saveQuietly();
                        continue;
                    }

                    if ($activity->is_all_day) {
                        $park = ThemePark::withTrashed()->find($activity->park_id);
                        if ($park === null) {
                            $schedule->status = ParkActivitySchedule::STATUS_CANCELLED;
                            $schedule->saveQuietly();
                            continue;
                        }

                        $hours = $park->effectiveHoursOn($schedule->date);
                        if ($hours['status'] !== 'open') {
                            $schedule->status = ParkActivitySchedule::STATUS_CANCELLED;
                            $schedule->saveQuietly();
                            continue;
                        }

                        $schedule->start_time = $hours['open_time'];
                        $schedule->end_time = $hours['close_time'];
                        $schedule->saveQuietly();
                        continue;
                    }

                    if ($activity->duration === null || $schedule->start_time === null) {
                        $schedule->status = ParkActivitySchedule::STATUS_CANCELLED;
                        $schedule->saveQuietly();
                        continue;
                    }

                    $end = CarbonImmutable::createFromFormat('H:i:s', $schedule->start_time)
                        ->addMinutes((int) $activity->duration);

                    $schedule->end_time = $end->format('H:i:s');
                    $schedule->saveQuietly();
                }
            });

        // Any rows the loop couldn't resolve get a placeholder window equal to
        // their start_time + 1 minute so the NOT NULL constraint can land.
        // Such rows are already status=cancelled, so they're never bookable.
        ParkActivitySchedule::withTrashed()
            ->whereNull('end_time')
            ->whereNotNull('start_time')
            ->chunkById(200, function ($schedules) {
                foreach ($schedules as $schedule) {
                    $end = CarbonImmutable::createFromFormat('H:i:s', $schedule->start_time)
                        ->addMinute();
                    $schedule->end_time = $end->format('H:i:s');
                    $schedule->saveQuietly();
                }
            });

        // Last-ditch: rows with no start_time AND no end_time. Hard-delete
        // (not soft-delete) since they're pure DB junk. The explicit
        // start_time NULL check pins this to the documented intent — without
        // it, any future bug in the loops above would silently delete real
        // schedule history.
        ParkActivitySchedule::withTrashed()
            ->whereNull('end_time')
            ->whereNull('start_time')
            ->forceDelete();

        Schema::table('park_activity_schedules', function (Blueprint $table) {
            $table->time('end_time')->nullable(false)->change();
        });

        // Belt-and-braces: SQLite (used by the test suite) sometimes retains
        // residual unique constraints across ALTER paths. Drop+recreate the
        // partial slot unique to guarantee the live-only WHERE clause is the
        // only enforcement, never a stale full unique.
        DB::statement('DROP INDEX IF EXISTS park_activity_schedule_unique_slot');
        DB::statement("CREATE UNIQUE INDEX park_activity_schedule_unique_slot ON park_activity_schedules (park_activity_id, date, start_time) WHERE deleted_at IS NULL AND status <> 'cancelled'");
    }

    public function down(): void
    {
        Schema::table('park_activity_schedules', function (Blueprint $table) {
            $table->time('end_time')->nullable()->change();
        });
    }
};
