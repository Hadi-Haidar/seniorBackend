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
        Schema::table('products', function (Blueprint $table) {
            // Add indexes for frequently queried columns
            $table->index(['room_id', 'status'], 'products_room_status_idx');
            $table->index(['room_id', 'status', 'created_at'], 'products_room_status_created_idx');
            $table->index(['room_id', 'category', 'status'], 'products_room_category_status_idx');
            $table->index(['status', 'created_at'], 'products_status_created_idx');
            $table->index(['price'], 'products_price_idx');
            $table->index(['category'], 'products_category_idx');
            $table->index(['name'], 'products_name_idx');
            $table->index(['visibility', 'status'], 'products_visibility_status_idx');
        });

        Schema::table('product_images', function (Blueprint $table) {
            // Add sort_order column and index
            $table->integer('sort_order')->default(0)->after('file_path');
            $table->index(['product_id', 'sort_order'], 'product_images_sort_idx');
        });

        Schema::table('product_favorites', function (Blueprint $table) {
            // Add composite index for bulk favorite queries
            $table->index(['user_id', 'product_id'], 'favorites_user_product_idx');
        });

        Schema::table('product_reviews', function (Blueprint $table) {
            // Add index for rating aggregations
            $table->index(['product_id', 'rating'], 'reviews_product_rating_idx');
        });

        Schema::table('rooms', function (Blueprint $table) {
            // Add index for commercial room queries
            $table->index(['is_commercial', 'id'], 'rooms_commercial_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_room_status_idx');
            $table->dropIndex('products_room_status_created_idx');
            $table->dropIndex('products_room_category_status_idx');
            $table->dropIndex('products_status_created_idx');
            $table->dropIndex('products_price_idx');
            $table->dropIndex('products_category_idx');
            $table->dropIndex('products_name_idx');
            $table->dropIndex('products_visibility_status_idx');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropIndex('product_images_sort_idx');
            $table->dropColumn('sort_order');
        });

        Schema::table('product_favorites', function (Blueprint $table) {
            $table->dropIndex('favorites_user_product_idx');
        });

        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropIndex('reviews_product_rating_idx');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropIndex('rooms_commercial_idx');
        });
    }
};
