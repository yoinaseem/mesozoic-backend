<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Postgres partial unique indexes for the beach domain. Two indexes:
 *
 *   1. Replaces the existing FULL slot unique on beach_activity_schedules
 *      (created in 2026_04_17_112153, named 'beach_activity_schedule_unique_slot')
 *      with a same-named PARTIAL unique that excludes cancelled rows. Cancelled
 *      schedules logically vacate their slot — staff should be able to recreate
 *      a schedule at the same (activity, date, start_time) after cancelling the
 *      original. (Same gap park had pre-DESD-95 a873f57.) When soft-deletes for
 *      beach land in a follow-up ticket, the predicate will need to extend to
 *      `WHERE deleted_at IS NULL AND status <> 'cancelled'`.
 *
 *   2. New partial unique on beach_bookings (reservation_id, beach_activity_schedule_id)
 *      WHERE status = 'confirmed'. DB-level race-safe backstop for the
 *      controller-level duplicate check; cancelled rows don't count, so a
 *      re-book after staff-cancel is allowed.
 *
 * On Postgres, $table->unique([cols], 'name') creates a UNIQUE CONSTRAINT
 * (backed by an index of the same name); DROP INDEX won't drop the
 * constraint. Use Laravel's dropUnique which generates the driver-correct
 * DDL (ALTER TABLE DROP CONSTRAINT on Postgres, DROP INDEX on SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beach_activity_schedules', function (Blueprint $table) {
            $table->dropUnique('beach_activity_schedule_unique_slot');
        });

        DB::statement("CREATE UNIQUE INDEX beach_activity_schedule_unique_slot ON beach_activity_schedules (beach_activity_id, activity_date, start_time) WHERE status <> 'cancelled'");
        DB::statement("CREATE UNIQUE INDEX beach_booking_unique_confirmed ON beach_bookings (reservation_id, beach_activity_schedule_id) WHERE status = 'confirmed'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS beach_booking_unique_confirmed');
        DB::statement('DROP INDEX IF EXISTS beach_activity_schedule_unique_slot');

        Schema::table('beach_activity_schedules', function (Blueprint $table) {
            $table->unique(
                ['beach_activity_id', 'activity_date', 'start_time'],
                'beach_activity_schedule_unique_slot'
            );
        });
    }
};
