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
        Schema::create('youtube_quota_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
            $table->date('quota_date');
            $table->unsignedInteger('units_used')->default(0);
            $table->unsignedInteger('uploads_count')->default(0);
            $table->unsignedInteger('reads_count')->default(0);
            $table->timestamps();

            $table->unique(['social_account_id', 'quota_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('youtube_quota_usages');
    }
};
