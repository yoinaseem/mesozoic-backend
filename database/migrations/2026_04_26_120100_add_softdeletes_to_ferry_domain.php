<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds soft-deletes to the ferry catalogue (FerryType, Ferry, FerrySchedule)
 * so the cascade-delete flow in FerryScheduleController / FerryController /
 * FerryTypeController can deactivate rows while preserving booking audit
 * history. Mirrors DESD-86 (hotel-stack) and DESD-89 (park-domain) patterns.
 *
 * Slot uniqueness on ferry_schedules (ferry_id, departure_time) becomes
 * partial — `WHERE deleted_at IS NULL` — so a soft-deleted slot vacates its
 * (ferry, departure_time) pair and the operator can recreate a slot at the
 * same departure_time after archiving the original.
 *
 * Postgres + SQLite both support partial unique indexes via DB::statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ferry_types', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('ferries', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('ferry_schedules', function (Blueprint $table) {
            $table->softDeletes();
            $table->dropUnique('ferry_schedule_unique_slot');
        });

        DB::statement('CREATE UNIQUE INDEX ferry_schedule_unique_slot ON ferry_schedules (ferry_id, departure_time) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ferry_schedule_unique_slot');

        Schema::table('ferry_schedules', function (Blueprint $table) {
            $table->unique(['ferry_id', 'departure_time'], 'ferry_schedule_unique_slot');
            $table->dropSoftDeletes();
        });
        Schema::table('ferries', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('ferry_types', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
