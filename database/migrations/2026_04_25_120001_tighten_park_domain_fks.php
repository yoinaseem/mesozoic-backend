<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match the DESD-86 posture in the park domain: every cascade FK flipped to
 * restrict so a future forceDelete() can't chain-wipe child rows silently.
 * park_bookings.park_id and park_activity_bookings.park_activity_schedule_id
 * are already restrict — no change needed there.
 *
 * Also drops the DB-level unique on park_activity_schedules slot so an archived
 * schedule doesn't block creating a live one at the same slot. Uniqueness moves
 * to the controller's existing closure check, adapted with whereNull deleted_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('park_opening_hours', function (Blueprint $table) {
            $table->dropForeign(['park_id']);
            $table->foreign('park_id')->references('id')->on('theme_parks')->restrictOnDelete();
        });

        Schema::table('park_hour_overrides', function (Blueprint $table) {
            $table->dropForeign(['park_id']);
            $table->foreign('park_id')->references('id')->on('theme_parks')->restrictOnDelete();
        });

        Schema::table('park_activities', function (Blueprint $table) {
            $table->dropForeign(['park_id']);
            $table->foreign('park_id')->references('id')->on('theme_parks')->restrictOnDelete();
        });

        Schema::table('park_activity_schedules', function (Blueprint $table) {
            $table->dropUnique('park_activity_schedule_unique_slot');
            $table->dropForeign(['park_activity_id']);
            $table->foreign('park_activity_id')->references('id')->on('park_activities')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('park_activity_schedules', function (Blueprint $table) {
            $table->dropForeign(['park_activity_id']);
            $table->foreign('park_activity_id')->references('id')->on('park_activities')->cascadeOnDelete();
            $table->unique(['park_activity_id', 'date', 'start_time'], 'park_activity_schedule_unique_slot');
        });

        Schema::table('park_activities', function (Blueprint $table) {
            $table->dropForeign(['park_id']);
            $table->foreign('park_id')->references('id')->on('theme_parks')->cascadeOnDelete();
        });

        Schema::table('park_hour_overrides', function (Blueprint $table) {
            $table->dropForeign(['park_id']);
            $table->foreign('park_id')->references('id')->on('theme_parks')->cascadeOnDelete();
        });

        Schema::table('park_opening_hours', function (Blueprint $table) {
            $table->dropForeign(['park_id']);
            $table->foreign('park_id')->references('id')->on('theme_parks')->cascadeOnDelete();
        });
    }
};
