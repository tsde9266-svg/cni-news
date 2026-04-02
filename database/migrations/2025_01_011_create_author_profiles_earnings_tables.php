<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STAGE 1 ADDENDUM — Author Profiles & Earnings
 *
 * What was missing from Stage 1:
 *  - No author public profile (bio, social links, byline)
 *  - No author settings (can they publish directly or need editor approval?)
 *  - No earnings / monetisation tracking
 *  - No payout records (when CNI pays an author)
 *  - No author application flow (someone applying to become a contributor)
 *
 * Design decision: author_profiles is separate from users.
 * Reason: not every user is an author. Keeps users table clean.
 * An author profile is created when a journalist/contributor role is assigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── author_profiles ────────────────────────────────────────────────
        // Public-facing author page data. One per user who writes content.
        Schema::create('author_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique(); // 1-to-1 with users
            $table->string('pen_name', 100)->nullable();     // public display name override
            $table->string('byline', 200)->nullable();       // short one-liner: "Senior Reporter, Kashmir Desk"
            $table->text('bio')->nullable();                  // full author bio (shown on author page)
            $table->string('bio_short', 255)->nullable();    // 1–2 sentence version for article footer
            $table->unsignedBigInteger('profile_photo_media_id')->nullable();

            // Social links — nullable, author fills in what they have
            $table->string('twitter_url', 255)->nullable();
            $table->string('facebook_url', 255)->nullable();
            $table->string('instagram_url', 255)->nullable();
            $table->string('linkedin_url', 255)->nullable();
            $table->string('website_url', 255)->nullable();
            $table->string('youtube_url', 255)->nullable();

            // Publishing permissions
            // can_self_publish = true  → article goes live immediately on author publish action
            // can_self_publish = false → article moves to pending_review for editor approval
            $table->boolean('can_self_publish')->default(false);

            // Monetisation status — off by default, admin enables per author
            $table->boolean('is_monetised')->default(false);

            // Default rate for this author (can be overridden per article)
            // NULL = not yet set / not eligible
            $table->enum('default_rate_type', ['per_article', 'per_word', 'per_view', 'flat_monthly'])
                  ->nullable();
            $table->decimal('default_rate_amount', 8, 4)->nullable(); // e.g. 5.0000 per article
            $table->char('rate_currency', 3)->default('GBP');

            // Payout preferences
            $table->enum('payout_method', ['bank_transfer', 'paypal', 'stripe_connect', 'cheque'])
                  ->nullable();
            $table->json('payout_details_encrypted')->nullable();
            // Stored encrypted. For bank: sort code + account.
            // For PayPal: email. For Stripe Connect: account ID.

            $table->boolean('is_active')->default(true);
            $table->timestamp('profile_verified_at')->nullable(); // admin verified identity
            $table->softDeletes();
            $table->timestamps();

            $table->index('user_id');
            $table->index('is_monetised');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('profile_photo_media_id')
                  ->references('id')->on('media_assets')->nullOnDelete();
        });

        // ── author_earnings ────────────────────────────────────────────────
        // One row per earning event. Immutable — append only.
        // Earnings are CALCULATED here but not PAID here (see author_payouts).
        Schema::create('author_earnings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author_profile_id');
            $table->unsignedBigInteger('article_id')->nullable(); // which article earned this
            $table->enum('earning_type', [
                'per_article',    // flat fee per published article
                'per_word',       // word count × rate
                'per_view',       // view count × rate (settled monthly)
                'flat_monthly',   // monthly retainer
                'bonus',          // one-off bonus from admin
                'adjustment',     // admin correction (can be negative)
            ]);
            $table->decimal('amount', 10, 4);        // can be negative for adjustments
            $table->char('currency', 3)->default('GBP');
            $table->string('description', 255)->nullable(); // "500 views × £0.002"
            $table->unsignedInteger('units')->nullable();   // word count, view count etc.
            $table->decimal('rate_applied', 8, 4)->nullable();
            $table->enum('status', ['pending', 'approved', 'paid', 'disputed', 'voided'])
                  ->default('pending');
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->unsignedBigInteger('payout_id')->nullable(); // FK added below
            $table->timestamp('earned_at')->useCurrent();
            $table->timestamps();

            $table->index(['author_profile_id', 'status']);
            $table->index('article_id');
            $table->index('earned_at');

            $table->foreign('author_profile_id')
                  ->references('id')->on('author_profiles')->cascadeOnDelete();
            $table->foreign('article_id')
                  ->references('id')->on('articles')->nullOnDelete();
            $table->foreign('approved_by_user_id')
                  ->references('id')->on('users')->nullOnDelete();
        });

        // ── author_payouts ─────────────────────────────────────────────────
        // Actual payment runs: admin batches approved earnings and marks as paid.
        // Integrates with Stripe Connect or manual bank transfer records.
        Schema::create('author_payouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author_profile_id');
            $table->unsignedBigInteger('processed_by_user_id'); // finance_admin who ran payout
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('GBP');
            $table->enum('method', ['bank_transfer', 'paypal', 'stripe_connect', 'cheque'])
                  ->default('bank_transfer');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending');
            $table->string('reference', 100)->nullable(); // bank ref, Stripe transfer ID etc.
            $table->text('notes')->nullable();            // internal finance notes
            $table->date('period_from')->nullable();      // earnings period covered
            $table->date('period_to')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['author_profile_id', 'status']);
            $table->index('paid_at');

            $table->foreign('author_profile_id')
                  ->references('id')->on('author_profiles')->cascadeOnDelete();
            $table->foreign('processed_by_user_id')
                  ->references('id')->on('users');
        });

        // Now add payout_id FK back on author_earnings
        Schema::table('author_earnings', function (Blueprint $table) {
            $table->foreign('payout_id')
                  ->references('id')->on('author_payouts')->nullOnDelete();
        });

        // ── author_article_rates ───────────────────────────────────────────
        // Per-article rate override — admin can set a different rate for
        // a specific article (e.g. special investigation piece = higher rate).
        Schema::create('author_article_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('article_id')->unique();
            $table->unsignedBigInteger('set_by_user_id');
            $table->enum('rate_type', ['per_article', 'per_word', 'per_view', 'flat_monthly']);
            $table->decimal('rate_amount', 8, 4);
            $table->char('currency', 3)->default('GBP');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->foreign('set_by_user_id')->references('id')->on('users');
        });

        // ── contributor_applications ───────────────────────────────────────
        // Anyone can apply to write for CNI. Admin reviews and approves/rejects.
        Schema::create('contributor_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable(); // null = new user applying
            $table->string('full_name', 150);
            $table->string('email', 255);
            $table->string('phone', 30)->nullable();
            $table->text('writing_experience');              // what have they written before
            $table->text('sample_article_url')->nullable();  // link to previous work
            $table->text('topics_of_interest')->nullable();  // Pakistan, Kashmir, Sport etc.
            $table->enum('preferred_language', ['en', 'ur', 'pa', 'mi', 'multiple'])
                  ->default('en');
            $table->boolean('wants_payment')->default(false); // do they want to be paid?
            $table->enum('status', ['pending', 'approved', 'rejected', 'on_hold'])
                  ->default('pending');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // ── Add article_word_count to articles ─────────────────────────────
        // Needed for per_word earnings calculation — stored at publish time.
        Schema::table('articles', function (Blueprint $table) {
            $table->unsignedInteger('word_count')->default(0)->after('view_count');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('word_count');
        });
        Schema::dropIfExists('contributor_applications');
        Schema::dropIfExists('author_article_rates');
        Schema::table('author_earnings', function (Blueprint $table) {
            $table->dropForeign(['payout_id']);
        });
        Schema::dropIfExists('author_payouts');
        Schema::dropIfExists('author_earnings');
        Schema::dropIfExists('author_profiles');
    }
};
