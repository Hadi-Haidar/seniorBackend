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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->onDelete('cascade');
    
    $table->string('name');
    $table->text('description')->nullable();
    $table->integer('price'); 
    $table->string('category')->nullable();
    $table->integer('stock')->default(0);
    $table->enum('status', ['active', 'inactive'])->default('active');
    $table->enum('visibility', ['private', 'public'])->default('private');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
