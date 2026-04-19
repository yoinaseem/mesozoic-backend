<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('park_hour_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('park_id')->constrained('theme_parks')->cascadeOnDelete();
            $table->date('date');
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['park_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('park_hour_overrides');
    }
};
