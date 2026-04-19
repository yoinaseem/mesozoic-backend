<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('park_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('park_id')->constrained('theme_parks')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->string('image')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedInteger('max_capacity');
            $table->boolean('is_all_day')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('park_activities');
    }
};
