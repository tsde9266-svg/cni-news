<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates seo_redirects table used by:
 * - SeoRedirectMiddleware (web requests)
 * - ImportWordPressCommand (auto-creates redirects for imported articles)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('old_path', 500)->unique()->comment('Old URL path, e.g. /2024/01/my-article/');
            $table->string('new_path', 500)->comment('New CNI URL path, e.g. /article/my-article');
            $table->unsignedSmallInteger('http_code')->default(301)->comment('301 permanent, 302 temporary');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('hit_count')->default(0)->comment('How many times this redirect was triggered');
            $table->string('notes', 255)->nullable()->comment('Internal note about why this redirect exists');
            $table->timestamps();

            $table->index(['old_path', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_redirects');
    }
};
