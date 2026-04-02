<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {

            $table->id();

            // Foreign keys
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('event_type_id')->nullable();
            $table->unsignedBigInteger('organizer_user_id')->nullable();

            // Event details
            $table->string('title');
            $table->text('description')->nullable();

            $table->string('location_name')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('country', 10)->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->boolean('is_public')->default(true);
            $table->enum('status', ['draft', 'published', 'cancelled'])->default('draft');

            $table->unsignedBigInteger('cover_media_id')->nullable();

            $table->decimal('ticket_price', 10, 2)->default(0);
            $table->integer('max_capacity')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes for faster queries
            $table->index(['channel_id', 'status']);
            $table->index('starts_at');

            // Optional foreign key constraints
            $table->foreign('channel_id')->references('id')->on('channels')->onDelete('cascade');
            $table->foreign('event_type_id')->references('id')->on('event_types')->onDelete('set null');
            $table->foreign('organizer_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('cover_media_id')->references('id')->on('media_assets')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};