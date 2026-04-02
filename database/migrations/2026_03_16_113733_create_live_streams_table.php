<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_streams', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('channel_id');

            $table->string('primary_platform', 50);
            $table->string('platform_stream_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();

            $table->enum('status', ['scheduled', 'live', 'ended'])->default('scheduled');

            $table->boolean('is_public')->default(true);

            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('actual_start_at')->nullable();

            $table->unsignedInteger('peak_viewers')->nullable();

            $table->timestamps();

            $table->index('channel_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_streams');
    }
};