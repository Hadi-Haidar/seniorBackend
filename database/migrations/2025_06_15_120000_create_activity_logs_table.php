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
    {//Done 100%
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->onDelete('cascade');
            $table->string('action'); //"Deleted Room", "Banned User"
            $table->string('target'); //"Room #22", "User: @email.com"
            $table->text('details')->nullable(); // Additional details about the action
            $table->enum('category', [
                'Room Management',
                'User Management', 
                'Payment Management',
                'Notification Management'
            ]);
            $table->enum('severity', ['Low', 'Medium', 'High', 'Critical']);
            $table->ipAddress('ip_address');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
}; 