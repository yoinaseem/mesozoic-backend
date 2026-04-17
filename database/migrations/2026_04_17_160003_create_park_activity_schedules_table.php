<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('park_activity_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('park_activity_id')->constrained('park_activities')->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->time('scheduled_time');
            $table->enum('status', ['scheduled', 'cancelled', 'completed'])->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('scheduled_date');
            $table->index('status');
            $table->unique(
                ['park_activity_id', 'scheduled_date', 'scheduled_time'],
                'park_activity_schedule_unique_slot'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('park_activity_schedules');
    }
};
