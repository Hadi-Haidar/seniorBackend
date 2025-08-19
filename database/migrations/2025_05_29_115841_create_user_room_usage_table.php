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
        //Done 100%
        Schema::create('user_room_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Monthly tracking for all subscription levels
            $table->integer('usage_year');
            $table->integer('usage_month');
            $table->integer('monthly_rooms_created')->default(0);
            
            $table->timestamps();
            
            // Indexes for performance
            $table->unique(['user_id', 'usage_year', 'usage_month']); // One record per user per month
            $table->index(['user_id', 'usage_year', 'usage_month']); // For monthly queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_room_usage');
    }
};
