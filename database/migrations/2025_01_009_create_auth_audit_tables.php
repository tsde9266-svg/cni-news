<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── personal_access_tokens ─────────────────────────────────────────
        // Laravel Sanctum's default table — kept as-is for compatibility
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // ── device_tokens ──────────────────────────────────────────────────
        // Firebase Cloud Messaging tokens for push notifications
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token', 500);
            $table->enum('platform', ['web', 'android', 'ios'])->default('web');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'token']);
            $table->index(['user_id', 'is_active']);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // ── login_history ──────────────────────────────────────────────────
        Schema::create('login_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('country', 60)->nullable();   // from IP geo-lookup
            $table->boolean('success')->default(true);
            $table->string('failure_reason', 100)->nullable();
            $table->timestamp('login_at')->useCurrent();

            $table->index(['user_id', 'login_at']);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // ── audit_logs ─────────────────────────────────────────────────────
        // Immutable ERP audit trail. NEVER update or delete rows here.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable(); // null = system action
            $table->string('action', 100);
            /*
             * Action examples:
             * article_created, article_published, article_archived,
             * employee_card_issued, employee_card_revoked,
             * live_stream_started, live_stream_ended,
             * membership_activated, membership_canceled,
             * promo_code_created, promo_code_used,
             * user_suspended, user_role_assigned
             */
            $table->string('target_type', 60)->nullable(); // article | employee_card | user | membership
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // NOTE: No updated_at — this table is append-only

            $table->index(['target_type', 'target_id']);
            $table->index('actor_user_id');
            $table->index('created_at');
            $table->index('action');

            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // ── password_reset_tokens ──────────────────────────────────────────
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('login_history');
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('personal_access_tokens');
    }
};
