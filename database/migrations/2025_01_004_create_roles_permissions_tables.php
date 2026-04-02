<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── roles ──────────────────────────────────────────────────────────
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_system_role')->default(false); // system roles cannot be deleted
            $table->timestamps();
        });

        // ── permissions ────────────────────────────────────────────────────
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique(); // e.g. article.publish
            $table->string('module', 60);         // articles, media, users, ads, events, hr, security
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('module');
        });

        // ── role_permission_map ────────────────────────────────────────────
        Schema::create('role_permission_map', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        // ── user_role_map ──────────────────────────────────────────────────
        Schema::create('user_role_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('channel_id')->nullable(); // scoped assignment
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'channel_id']);
            $table->index('user_id');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_map');
        Schema::dropIfExists('role_permission_map');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
