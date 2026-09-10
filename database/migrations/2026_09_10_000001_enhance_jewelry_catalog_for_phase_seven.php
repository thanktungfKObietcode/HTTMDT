<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_new_arrival')->default(false)->after('featured');
            $table->boolean('is_bestseller')->default(false)->after('is_new_arrival');
            $table->unsignedInteger('sort_order')->default(0)->after('is_bestseller');
            $table->index(['is_active', 'featured', 'sort_order'], 'products_merchandising_index');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->string('alt_text')->nullable()->after('image_path');
            $table->boolean('is_active')->default(true)->after('is_primary');
            $table->index(['product_id', 'is_active', 'sort_order'], 'product_images_display_index');
        });

        Schema::table('product_specifications', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('value');
            $table->boolean('is_active')->default(true)->after('sort_order');
            $table->index(['product_id', 'is_active', 'sort_order'], 'product_specifications_display_index');
        });
    }

    public function down(): void
    {
        Schema::table('product_specifications', function (Blueprint $table) {
            $table->dropIndex('product_specifications_display_index');
            $table->dropColumn(['sort_order', 'is_active']);
        });
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropIndex('product_images_display_index');
            $table->dropColumn(['alt_text', 'is_active']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_merchandising_index');
            $table->dropColumn(['is_new_arrival', 'is_bestseller', 'sort_order']);
        });
    }
};
