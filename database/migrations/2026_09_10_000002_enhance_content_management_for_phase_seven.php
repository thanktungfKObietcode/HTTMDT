<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_categories', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('slug');
        });

        Schema::table('blog_posts', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('is_published');
            $table->index(['is_published', 'published_at']);
        });

        Schema::table('banners', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('position');
            $table->timestamp('starts_at')->nullable()->after('is_active');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
            $table->index(['position', 'is_active', 'sort_order'], 'banners_display_index');
        });

        DB::table('permissions')->insertOrIgnore([
            'name' => 'content.manage',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep permission/role assignments intact during rollback; removing RBAC data is unsafe.

        Schema::table('banners', function (Blueprint $table) {
            $table->dropIndex('banners_display_index');
            $table->dropColumn(['sort_order', 'starts_at', 'ends_at']);
        });
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropIndex(['is_published', 'published_at']);
            $table->dropColumn('published_at');
        });
        Schema::table('blog_categories', fn (Blueprint $table) => $table->dropColumn('is_active'));
    }
};
