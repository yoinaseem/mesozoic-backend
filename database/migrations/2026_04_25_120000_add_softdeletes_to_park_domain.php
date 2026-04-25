<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete on the park domain (DESD-89). Mirrors DESD-86 (hotel stack) for
 * the ThemePark → ParkActivity → ParkActivitySchedule chain so archived parks
 * preserve historical ParkBooking + ParkActivityBooking context.
 *
 * Park/ParkActivity bookings stay on their existing status='cancelled'
 * soft-cancel pattern; no deleted_at column on those tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theme_parks', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('park_activities', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('park_activity_schedules', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('park_activity_schedules', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('park_activities', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('theme_parks', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
