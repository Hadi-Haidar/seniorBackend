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
        Schema::create('coin_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            $table->enum('direction', ['in', 'out']); // in = user gained coins, out = user spent
            $table->integer('amount');
            
            $table->enum('source_type', ['purchase', 'reward', 'referral', 'system', 'spend']); // where it came from
            $table->string('action'); // description, e.g. , 'buy_100_coins', 'event_reward'

            $table->text('notes')->nullable(); // The notes field is optional, human-readable text used for more detailed descriptions, such as metadata, system decisions, or admin remarks.

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coin_transactions');
    }
};
