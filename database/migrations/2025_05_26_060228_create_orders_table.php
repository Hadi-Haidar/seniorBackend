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
    { //Done 100%
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('buyer_id')->constrained('users')->onDelete('cascade');
            $table->string('batch_id')->nullable();
            $table->unsignedBigInteger('parent_order_id')->nullable();
            $table->integer('quantity');
            $table->integer('total_price'); // could also use decimal(10, 2)
            $table->string('phone_number');
            $table->string('address');
            $table->string('city');
            $table->text('delivery_notes')->nullable();
            $table->enum('status', ['pending', 'cancelled', 'accepted', 'rejected', 'delivered'])->default('pending');
            $table->enum('placed_from', ['store', 'room'])->default('store');
            
            // Foreign key for parent order
            $table->foreign('parent_order_id')->references('id')->on('orders')->onDelete('cascade');
            
            // Indexes for better performance
            $table->index('batch_id'); // Add index for faster grouping queries
            $table->index('parent_order_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
