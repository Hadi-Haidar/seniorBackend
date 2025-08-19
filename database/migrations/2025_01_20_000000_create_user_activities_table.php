<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->date('activity_date'); // Track daily activity
            $table->integer('total_minutes')->default(0); // Total active minutes for the day
            $table->timestamp('last_activity_at')->nullable(); // Last recorded activity
            $table->timestamps();

            // Ensure one record per user per day
            $table->unique(['user_id', 'activity_date']);
            
            // Add index for performance
            $table->index(['user_id', 'activity_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_activities');
    }
}; 