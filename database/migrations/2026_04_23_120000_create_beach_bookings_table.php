<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beach_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beach_activity_schedule_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('guests');
            $table->string('status', 16)->default('confirmed');
            $table->decimal('price_per_guest', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['beach_activity_schedule_id', 'status']);
            $table->index(['reservation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beach_bookings');
    }
};
