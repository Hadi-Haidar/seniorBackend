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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('type'); // post_like, post_comment, room_join_request, order_placed, payment_status
            $table->string('title');
            $table->text('message'); 
            $table->json('data')->nullable(); // Additional data like post_id, order_id,
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();//aymta luser 3mela mark as read 
            $table->string('action_url')->nullable(); // URL to redirect when clicked
            $table->foreignId('related_user_id')->nullable()->constrained('users')->onDelete('cascade');    //User A likes User B's post
                                                                                                         // Notification created:
                                                                                                       //  user_id = User B (receives the notification)
                                                                                                        // related_user_id = User A (the person who liked the post)

            $table->string('admin_notification_type')->nullable();//no3 lnotification le bado yeb3ata l admin (promotion/warning...)
            $table->timestamp('user_deleted_at')->nullable();//aymta luser ma7aha
            $table->timestamps();

            // Indexes for better performance
            $table->index(['user_id', 'is_read']);
            $table->index(['user_id', 'type']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
