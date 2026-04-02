<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── categories ─────────────────────────────────────────────────────
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('parent_id')->nullable(); // hierarchical
            $table->string('slug', 150);
            $table->string('default_name', 150);
            $table->text('default_description')->nullable();
            $table->unsignedBigInteger('cover_media_id')->nullable();
            $table->unsignedInteger('position')->default(0); // menu ordering
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['channel_id', 'slug']);
            $table->index(['channel_id', 'is_active']);
            $table->index('parent_id');

            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
            $table->foreign('cover_media_id')->references('id')->on('media_assets')->nullOnDelete();
        });

        // ── category_translations ──────────────────────────────────────────
        Schema::create('category_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('language_id');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'language_id']);

            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->foreign('language_id')->references('id')->on('languages')->cascadeOnDelete();
        });

        // ── tags ───────────────────────────────────────────────────────────
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('channel_id');
            $table->string('slug', 150);
            $table->string('default_name', 150);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'slug']);
            $table->index('channel_id');

            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
        });

        // ── tag_translations ───────────────────────────────────────────────
        Schema::create('tag_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tag_id');
            $table->unsignedBigInteger('language_id');
            $table->string('name', 150);
            $table->timestamps();

            $table->unique(['tag_id', 'language_id']);

            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
            $table->foreign('language_id')->references('id')->on('languages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_translations');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('category_translations');
        Schema::dropIfExists('categories');
    }
};
