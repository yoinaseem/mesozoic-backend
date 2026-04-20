<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ferries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable(); //remove this field and add to ferryType if we decide to implement ferry schedules, otherwise we'll refactor to simplify
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('capacity')->default(1);
            $table->string('image')->nullable(); //remove this field and add this to ferryType if we decide to implement ferry schedules, otherwise we'll refactor to simplify
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ferries');
    }
};
