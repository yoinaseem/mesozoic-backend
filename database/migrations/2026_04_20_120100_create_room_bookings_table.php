<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('confirmed');
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedSmallInteger('guests');
            $table->decimal('price_per_night', 10, 2);
            $table->unsignedSmallInteger('nights');
            $table->decimal('total_price', 10, 2);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['reservation_id', 'status']);
            $table->index(['room_type_id', 'check_in_date', 'check_out_date']);
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_bookings');
    }
};
