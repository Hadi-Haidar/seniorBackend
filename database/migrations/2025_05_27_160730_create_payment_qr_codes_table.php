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
        Schema::create('payment_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('qr_image')->nullable(); // QR code image path
            $table->string('wish_number')->default('81386402'); // Wish money number
            $table->text('description')->nullable(); // Optional description for the QR code
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_qr_codes');
    }
};
