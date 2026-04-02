<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('channel_id')->nullable(); // null = global account
            $table->string('email', 255)->unique();
            $table->string('phone', 30)->nullable()->unique();
            $table->string('password_hash', 255)->nullable(); // null = SSO-only
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('display_name', 100);
            $table->unsignedBigInteger('avatar_media_id')->nullable();
            $table->unsignedBigInteger('preferred_language_id')->nullable();
            $table->string('country', 60)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('timezone', 60)->default('Europe/London');
            $table->boolean('is_email_verified')->default(false);
            $table->boolean('is_phone_verified')->default(false);
            $table->enum('status', ['active', 'suspended', 'deleted'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes(); // deleted_at
            $table->timestamps();

            // Indexes
            $table->index('status');
            $table->index('channel_id');

            // Foreign keys (avatar_media_id added after media_assets table via separate migration)
            $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
            $table->foreign('preferred_language_id')->references('id')->on('languages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
