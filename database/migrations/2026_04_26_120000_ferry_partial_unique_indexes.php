<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Postgres partial unique index for ferry_bookings — DB-level race-safe
 * backstop for the controller-level per-(reservation, slot, date) duplicate
 * check. Cancelled rows don't count, so a reservation that staff-cancels
 * a ferry booking can rebook the same (slot, date) without colliding.
 *
 * Mirrors DESD-97 commit 1's beach_booking_unique_confirmed index. Slot
 * uniqueness on ferry_schedules itself is a plain full unique
 * (ferry_schedule_unique_slot from the slot-shape redesign) — slots have
 * no cancelled state, so a partial isn't needed there.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX ferry_booking_unique_confirmed ON ferry_bookings (reservation_id, ferry_schedule_id, travel_date) WHERE status = 'confirmed'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ferry_booking_unique_confirmed');
    }
};
