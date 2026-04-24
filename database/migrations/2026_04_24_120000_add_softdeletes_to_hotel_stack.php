<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Introduce soft-delete (archive) on the hotel stack. Bookings stay on the
 * status='cancelled'+cancelled_at soft pattern; the hotel/room_type/room
 * triple now supports archival so historical bookings keep resolvable
 * parent data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
