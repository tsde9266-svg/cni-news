<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── articles ───────────────────────────────────────────────────────
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('primary_language_id');
            $table->string('slug', 220);
            $table->enum('status', [
                'draft',
                'pending_review',
                'scheduled',
                'published',
                'archived',
            ])->default('draft');
            $table->enum('type', [
                'news',
                'opinion',
                'interview',
                'analysis',
                'bulletin',
                'sponsored',
            ])->default('news');
            $table->unsignedBigInteger('author_user_id');
            $table->unsignedBigInteger('editor_user_id')->nullable();
            $table->unsignedBigInteger('featured_image_media_id')->nullable();
            $table->unsignedBigInteger('main_category_id');
            $table->boolean('is_breaking')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('allow_comments')->default(true);
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Critical indexes for homepage and listing queries
            $table->unique(['channel_id', 'slug']);
            $table->index(['status', 'published_at']);
            $table->index(['channel_id', 'status', 'published_at']);
            $table->index('main_category_id');
            $table->index('is_breaking');
            $table->index('is_featured');
            $table->index('author_user_id');

            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
            $table->foreign('primary_language_id')->references('id')->on('languages');
            $table->foreign('author_user_id')->references('id')->on('users');
            $table->foreign('editor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('featured_image_media_id')->references('id')->on('media_assets')->nullOnDelete();
            $table->foreign('main_category_id')->references('id')->on('categories');
        });

        // ── article_translations ───────────────────────────────────────────
        Schema::create('article_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('language_id');
            $table->string('title', 320);
            $table->string('subtitle', 320)->nullable();
            $table->text('summary')->nullable();
            $table->longText('body');               // Rich HTML or JSON blocks (TipTap/Lexical)
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->string('seo_slug_override', 220)->nullable(); // Different slug per language
            $table->timestamps();

            $table->unique(['article_id', 'language_id']);
            $table->index(['article_id', 'language_id']);
            // Full-text search index on title + summary
            $table->fullText(['title', 'summary'], 'article_translations_fulltext');

            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->foreign('language_id')->references('id')->on('languages');
        });

        // ── article_versions ───────────────────────────────────────────────
        // Full edit history — never deleted. Enables rollback and diff views.
        Schema::create('article_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('language_id');
            $table->unsignedSmallInteger('version_number');
            $table->string('title', 320);
            $table->longText('body');
            $table->unsignedBigInteger('saved_by_user_id');
            $table->string('change_summary', 255)->nullable(); // "Fixed headline typo"
            $table->timestamp('created_at')->useCurrent();

            $table->index(['article_id', 'language_id', 'version_number']);

            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->foreign('language_id')->references('id')->on('languages');
            $table->foreign('saved_by_user_id')->references('id')->on('users');
        });

        // ── article_category_map ───────────────────────────────────────────
        // Secondary categories (many-to-many)
        Schema::create('article_category_map', function (Blueprint $table) {
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('category_id');
            $table->boolean('is_primary')->default(false);
            $table->primary(['article_id', 'category_id']);

            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
        });

        // ── article_tag_map ────────────────────────────────────────────────
        Schema::create('article_tag_map', function (Blueprint $table) {
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('tag_id');
            $table->primary(['article_id', 'tag_id']);

            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
        });

        // ── comments ───────────────────────────────────────────────────────
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('parent_comment_id')->nullable(); // threading
            $table->unsignedBigInteger('user_id')->nullable();           // null = guest
            $table->string('guest_name', 100)->nullable();
            $table->string('guest_email', 255)->nullable();
            $table->text('content');
            $table->enum('status', ['pending', 'approved', 'rejected', 'spam'])->default('pending');
            $table->decimal('spam_score', 4, 3)->nullable(); // 0.000 – 1.000 from Akismet
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['article_id', 'status']);
            $table->index('parent_comment_id');
            $table->index('status'); // for moderation queue

            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->foreign('parent_comment_id')->references('id')->on('comments')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        // ── content_locks ──────────────────────────────────────────────────
        // Prevents two editors saving the same article simultaneously
        Schema::create('content_locks', function (Blueprint $table) {
            $table->id();
            $table->string('content_type', 60); // article | video | event | live_stream
            $table->unsignedBigInteger('content_id');
            $table->unsignedBigInteger('locked_by_user_id');
            $table->timestamp('locked_at')->useCurrent();
            $table->timestamp('expires_at');      // auto-expire after 15 minutes of inactivity

            $table->unique(['content_type', 'content_id']); // only one lock per item
            $table->index('expires_at');          // scheduler cleans up expired locks

            $table->foreign('locked_by_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_locks');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('article_tag_map');
        Schema::dropIfExists('article_category_map');
        Schema::dropIfExists('article_versions');
        Schema::dropIfExists('article_translations');
        Schema::dropIfExists('articles');
    }
};
