<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('park_opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('park_id')->constrained('theme_parks')->cascadeOnDelete();
            $table->enum('day', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
            $table->time('open_time');
            $table->time('close_time');
            $table->timestamps();

            $table->unique(['park_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('park_opening_hours');
    }
};
