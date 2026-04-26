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
            $table->time('departure_time');
            $table->time('arrival_time');
            $table->string('departure_port');
            $table->string('arrival_port');
            $table->timestamps();

            $table->unique(['ferry_id', 'departure_time'], 'ferry_schedule_unique_slot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ferry_schedules');
    }
};
