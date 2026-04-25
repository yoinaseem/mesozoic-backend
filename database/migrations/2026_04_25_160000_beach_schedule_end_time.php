<?php

use App\Models\BeachActivity;
use App\Models\BeachActivitySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Model B for the beach domain: schedule.start_time / end_time are canonical.
 * Backfills end_time for every existing row, then makes the column NOT NULL.
 *
 * Beach has stronger schema invariants than park did pre-DESD-95:
 *   - beach_activities.duration is unsignedInteger NOT NULL (every activity
 *     has a real duration), so the timed-derive path always succeeds.
 *   - beach_activity_schedules.start_time is time NOT NULL.
 *   - No is_all_day flag on beach.
 * So the backfill is straightforward: end_time = start_time + duration with
 * Carbon overnight wrap. The defensive branches (parent activity missing,
 * placeholder pass, last-ditch hard-delete) are kept as belt-and-braces in
 * case dev/prod data drift produced rows the schema rules now exclude.
 *
 * After this migration, BeachActivity.duration is a UI default only — never
 * consumed at read time. Resources read end_time directly from the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beach_activity_schedules', function (Blueprint $table) {
            $table->time('end_time')->nullable()->after('start_time');
        });

        // chunkById, NOT chunk: each iteration mutates end_time, so rows leave
        // the result set as we process them. chunk()'s OFFSET-based paging
        // would skip records once the window shifts; chunkById uses
        // `WHERE id > last_seen` which is mutation-safe. Lifted from
        // DESD-95's a873f57 migration revision.
        BeachActivitySchedule::query()
            ->whereNull('end_time')
            ->chunkById(200, function ($schedules) {
                foreach ($schedules as $schedule) {
                    $activity = BeachActivity::find($schedule->beach_activity_id);

                    if ($activity === null) {
                        $schedule->status = BeachActivitySchedule::STATUS_CANCELLED;
                        $schedule->save();
                        continue;
                    }

                    if ($schedule->start_time === null) {
                        $schedule->status = BeachActivitySchedule::STATUS_CANCELLED;
                        $schedule->save();
                        continue;
                    }

                    $end = CarbonImmutable::createFromFormat('H:i:s', $schedule->start_time)
                        ->addMinutes((int) $activity->duration);

                    $schedule->end_time = $end->format('H:i:s');
                    $schedule->save();
                }
            });

        // Any rows the loop couldn't resolve (now status=cancelled) get a
        // placeholder window equal to start_time + 1 minute so the NOT NULL
        // constraint can land. Such rows are already cancelled, so they're
        // never bookable.
        BeachActivitySchedule::query()
            ->whereNull('end_time')
            ->whereNotNull('start_time')
            ->chunkById(200, function ($schedules) {
                foreach ($schedules as $schedule) {
                    $end = CarbonImmutable::createFromFormat('H:i:s', $schedule->start_time)
                        ->addMinute();
                    $schedule->end_time = $end->format('H:i:s');
                    $schedule->save();
                }
            });

        // Last-ditch: rows with no start_time AND no end_time. Hard-delete
        // since they're pure DB junk (start_time is NOT NULL on the column,
        // so this path should never fire — but the explicit start_time NULL
        // check pins the delete to documented intent. Without it, any future
        // bug leaving end_time null on rows with a real start_time would
        // silently delete real schedule history. Defensive pattern lifted
        // from DESD-95's a873f57.
        BeachActivitySchedule::query()
            ->whereNull('end_time')
            ->whereNull('start_time')
            ->delete();

        Schema::table('beach_activity_schedules', function (Blueprint $table) {
            $table->time('end_time')->nullable(false)->change();
        });

        // Belt-and-braces: SQLite (used by the test suite) rebuilds the
        // table on `Schema::table` ALTER paths — adding the `end_time`
        // column and then changing it to NOT NULL each rebuild the table,
        // and the rebuild can drop the partial slot unique created in
        // 2026_04_25_150000 (replacing it with whatever the original
        // create-table migration declared, i.e. a full unique on the same
        // name). Drop+recreate the partial unique here to guarantee the
        // live-only WHERE clause is the only enforcement, never a stale
        // full unique. Same pattern park's DESD-95 a873f57 added to the
        // park canonical-window migration.
        DB::statement('DROP INDEX IF EXISTS beach_activity_schedule_unique_slot');
        DB::statement("CREATE UNIQUE INDEX beach_activity_schedule_unique_slot ON beach_activity_schedules (beach_activity_id, activity_date, start_time) WHERE status <> 'cancelled'");
    }

    public function down(): void
    {
        Schema::table('beach_activity_schedules', function (Blueprint $table) {
            $table->dropColumn('end_time');
        });
    }
};
