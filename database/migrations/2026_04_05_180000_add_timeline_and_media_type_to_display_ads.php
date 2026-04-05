<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('display_ads', function (Blueprint $table) {
            // media_type: 'image' (default) or 'video'
            $table->string('media_type')->default('image')->after('image_url');
            // video_url: used when media_type = 'video'
            $table->text('video_url')->nullable()->after('media_type');
            // campaign timeline
            $table->timestamp('starts_at')->nullable()->after('display_order');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('display_ads', function (Blueprint $table) {
            $table->dropColumn(['media_type', 'video_url', 'starts_at', 'ends_at']);
        });
    }
};
