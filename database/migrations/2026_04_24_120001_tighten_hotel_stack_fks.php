<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Swap cascade FKs to restrict across the hotel stack now that soft-delete
 * is the real archival path. Cascades only fired on forceDelete() from here
 * on, and when they did the booking ledger would vanish — restricting
 * makes the DB refuse instead of silently wiping history.
 *
 * Also drops the DB-level unique(hotel_id, room_no) on rooms. With rows now
 * remaining as soft-deleted, that index would block reusing a room_no after
 * archival. Uniqueness moves to validation-layer via
 * Rule::unique('rooms')->whereNull('deleted_at') in RoomController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropForeign(['hotel_id']);
            $table->foreign('hotel_id')->references('id')->on('hotels')->restrictOnDelete();
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropUnique(['hotel_id', 'room_no']);
            $table->dropForeign(['hotel_id']);
            $table->dropForeign(['room_type_id']);
            $table->foreign('hotel_id')->references('id')->on('hotels')->restrictOnDelete();
            $table->foreign('room_type_id')->references('id')->on('room_types')->restrictOnDelete();
        });

        Schema::table('room_bookings', function (Blueprint $table) {
            $table->dropForeign(['hotel_id']);
            $table->dropForeign(['room_id']);
            $table->foreign('hotel_id')->references('id')->on('hotels')->restrictOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('room_bookings', function (Blueprint $table) {
            $table->dropForeign(['hotel_id']);
            $table->dropForeign(['room_id']);
            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['hotel_id']);
            $table->dropForeign(['room_type_id']);
            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->foreign('room_type_id')->references('id')->on('room_types')->cascadeOnDelete();
            $table->unique(['hotel_id', 'room_no']);
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->dropForeign(['hotel_id']);
            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
        });
    }
};
