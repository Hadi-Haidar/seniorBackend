<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    { //Done 100%
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            $table->string('title');
            $table->text('content');
            
            $table->enum('visibility', ['private', 'public'])->default('private'); // public shows on home feed too
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_featured')->default(false); // for promoted/ads
            $table->timestamp('scheduled_at')->nullable(); // for scheduling posts in future

            $table->timestamps();
        });

        // Populate published_at for existing public posts (if any exist after fresh migration)
        // This is mainly for data consistency in case of seeding
        DB::table('posts')
            ->where('visibility', 'public')
            ->whereNull('published_at')
            ->update([
                'published_at' => DB::raw('created_at')
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
