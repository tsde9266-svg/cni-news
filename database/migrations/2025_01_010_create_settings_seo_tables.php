<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── site_settings ──────────────────────────────────────────────────
        // Per-channel config: logo, colors, social links, analytics IDs, etc.
        // All editable from admin panel.
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('channel_id');
            $table->string('key', 100);
            $table->json('value')->nullable();
            /*
             * Key examples and their value shapes:
             * site_name          → "CNI News Network"
             * tagline            → "Your Voice, Your News"
             * logo_media_id      → 42
             * favicon_media_id   → 43
             * primary_color      → "#CF101A"   (UK red)
             * secondary_color    → "#003087"   (UK blue)
             * font_heading       → "Playfair Display"
             * font_body          → "Source Serif 4"
             * social_links       → {"facebook":"url","twitter":"url","youtube":"url"}
             * google_analytics_id→ "G-XXXXXXXXXX"
             * footer_text        → "© 2025 CNI News Network Ltd"
             * contact_email      → "news@cni.co.uk"
             * breaking_ticker    → true
             * maintenance_mode   → false
             */
            $table->timestamps();

            $table->unique(['channel_id', 'key']);
            $table->index('channel_id');

            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
        });

        // ── seo_redirects ──────────────────────────────────────────────────
        // WordPress legacy URL mapping — keeps Google rankings after migration
        Schema::create('seo_redirects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('channel_id')->nullable();
            $table->string('old_path', 500);
            $table->string('new_path', 500);
            $table->unsignedSmallInteger('http_code')->default(301); // 301 or 302
            $table->unsignedInteger('hit_count')->default(0);        // track usage
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('old_path');
            $table->index(['channel_id', 'is_active']);
        });

        // ── translations_generic ───────────────────────────────────────────
        // UI strings, email templates, system messages — all translatable
        Schema::create('translations_generic', function (Blueprint $table) {
            $table->id();
            $table->string('namespace', 60)->default('frontend');
            // namespace: frontend | backend | email | sms | push_notification
            $table->string('key', 200);
            $table->unsignedBigInteger('language_id');
            $table->text('value');
            $table->timestamps();

            $table->unique(['namespace', 'key', 'language_id']);
            $table->index(['namespace', 'language_id']);

            $table->foreign('language_id')->references('id')->on('languages')->cascadeOnDelete();
        });

        // ── wp_import_log ──────────────────────────────────────────────────
        // Tracks WordPress migration progress — one row per WP post
        Schema::create('wp_import_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wp_post_id');
            $table->unsignedBigInteger('mapped_article_id')->nullable();
            $table->json('wp_category_ids')->nullable();
            $table->enum('status', ['pending', 'imported', 'skipped', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['wp_post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_import_log');
        Schema::dropIfExists('translations_generic');
        Schema::dropIfExists('seo_redirects');
        Schema::dropIfExists('site_settings');
    }
};
