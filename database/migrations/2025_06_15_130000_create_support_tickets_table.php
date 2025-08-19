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
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->unique(); // TKT-001 format
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('subject');
            $table->text('description');
            $table->enum('category', [
                'Technical Issues',
                'Subscriptions', 
                'Security',
                'General Support',
                'Billing',
                'Account'
            ]);
            $table->enum('priority', ['Low', 'Medium', 'High']);
            $table->enum('status', ['Open', 'In Progress', 'Resolved', 'Closed'])->default('Open');
            $table->string('assigned_to')->nullable(); // Admin name or team 
            $table->foreignId('assigned_admin_id')->nullable()->constrained('users')->onDelete('set null');//stored in the database
            $table->timestamps();
            
             $table->index(['user_id', 'status']);
             $table->index(['status', 'priority']);
            $table->index('ticket_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
}; 