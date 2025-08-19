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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('payment_method', ['wishmoney'])->default('wishmoney'); // Only wishmoney payment method
            $table->enum('payment_status', ['pending', 'completed', 'cancelled', 'rejected'])->default('pending');
            $table->unsignedInteger('amount')->default(1)->check('amount >= 1 AND amount <= 10'); // Amount between 1-10 USD
            $table->string('transaction_id')->unique();
            $table->string('phone_no');
            $table->string('currency', 3)->default('USD');
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
