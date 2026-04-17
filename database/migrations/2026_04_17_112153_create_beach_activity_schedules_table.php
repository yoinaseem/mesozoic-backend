<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beach_activity_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beach_activity_id')->constrained()->cascadeOnDelete();
            $table->date('activity_date');
            $table->time('start_time');
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending');
            $table->timestamps();

            $table->index('activity_date');
            $table->index('status');
            $table->unique(
                ['beach_activity_id', 'activity_date', 'start_time'],
                'beach_activity_schedule_unique_slot'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beach_activity_schedules');
    }
};
