<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ferry_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ferry_id')->constrained()->cascadeOnDelete();
            $table->date('travel_date');
            $table->time('departure_time');
            $table->date('arrival_date');
            $table->time('arrival_time');
            $table->string('departure_port');
            $table->string('arrival_port');
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');
            $table->timestamps();

            $table->index('travel_date');
            $table->index('status');
            $table->unique(['ferry_id', 'travel_date', 'departure_time'], 'ferry_schedule_unique_departure');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ferry_schedules');
    }
};
