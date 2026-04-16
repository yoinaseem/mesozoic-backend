<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assigns users to specific hotels for resource-scoped RBAC.
     *
     * A row in this pivot means "user manages this hotel" — combined with the
     * `hotel-manager` role (checked via middleware), it lets HotelPolicy
     * authorise per-hotel update/delete actions.
     */
    public function up(): void
    {
        Schema::create('hotel_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'hotel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_user');
    }
};
