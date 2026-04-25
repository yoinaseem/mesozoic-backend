<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Postgres-only partial unique indexes that enforce uniqueness against live
 * rows only. Replaces the slot unique on park_activity_schedules dropped in
 * 2026_04_25_120001_tighten_park_domain_fks (which had to allow soft-deleted
 * rows to coexist with a new row at the same slot). Same pattern applied to
 * confirmed-booking duplicates so cancelled rows don't block re-booking.
 *
 * Controllers still run friendlier closure checks inside DB::transaction +
 * lockForUpdate; these indexes are the race-safe backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX park_activity_schedule_unique_slot ON park_activity_schedules (park_activity_id, date, start_time) WHERE deleted_at IS NULL AND status <> 'cancelled'");
        DB::statement("CREATE UNIQUE INDEX park_booking_unique_confirmed ON park_bookings (reservation_id, park_id, date) WHERE status = 'confirmed'");
        DB::statement("CREATE UNIQUE INDEX park_activity_booking_unique_confirmed ON park_activity_bookings (reservation_id, park_activity_schedule_id) WHERE status = 'confirmed'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS park_activity_booking_unique_confirmed');
        DB::statement('DROP INDEX IF EXISTS park_booking_unique_confirmed');
        DB::statement('DROP INDEX IF EXISTS park_activity_schedule_unique_slot');
    }
};
