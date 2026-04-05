<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facebook CDN URLs include large query-string tokens and exceed VARCHAR(500).
 * Widening media_url and thumbnail_url to TEXT (65 535 chars) fixes the
 * "String data, right truncated: 1406" error during social:ingest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_feed_items', function (Blueprint $table) {
            $table->text('media_url')->nullable()->change();
            $table->text('thumbnail_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('social_feed_items', function (Blueprint $table) {
            $table->string('media_url', 500)->nullable()->change();
            $table->string('thumbnail_url', 500)->nullable()->change();
        });
    }
};
