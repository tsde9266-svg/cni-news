<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── ad_packages ────────────────────────────────────────────────────
        // Admin-managed advertising packages shown on /advertise
        Schema::create('ad_packages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 150);
            $table->string('tagline', 255)->nullable();
            $table->text('description')->nullable();
            $table->enum('category', ['website', 'social', 'bundle'])->default('website');
            $table->string('placement', 100)->nullable(); // homepage_leaderboard, article_sidebar, facebook_post …
            $table->string('platform', 50)->nullable();   // facebook | youtube | instagram | twitter | null=website
            $table->decimal('price_amount', 8, 2)->default(0.00);
            $table->char('price_currency', 3)->default('GBP');
            $table->unsignedSmallInteger('duration_days')->default(7); // 7 = 1 week, 1 = single post
            $table->string('dimensions', 50)->nullable();              // "970x90", "300x250" etc.
            $table->unsignedSmallInteger('max_concurrent')->nullable(); // how many can run at once (null = unlimited)
            $table->json('features')->nullable();                       // array of bullet-point strings
            $table->boolean('is_featured')->default(false);            // show "Best Value" badge
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('icon_emoji', 10)->nullable();              // e.g. "📢" "📺" "📘"
            $table->string('stripe_price_id', 200)->nullable();       // one-time Stripe Price ID (optional)
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        // ── ad_bookings ────────────────────────────────────────────────────
        // One record per advertiser booking
        Schema::create('ad_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique(); // CNI-AD-000001
            $table->foreignId('ad_package_id')->constrained('ad_packages');

            // Advertiser contact (no login required)
            $table->string('advertiser_name', 150);
            $table->string('advertiser_email', 255);
            $table->string('advertiser_phone', 30)->nullable();
            $table->string('company_name', 150)->nullable();
            $table->string('company_website', 255)->nullable();

            // Campaign
            $table->string('campaign_title', 200)->nullable();
            $table->string('creative_url', 500)->nullable();   // hosted image/video URL for web ads
            $table->string('click_url', 500)->nullable();      // where the banner/post links to
            $table->text('brief_text')->nullable();            // for social posts: what to say

            // Schedule
            $table->date('start_date');
            $table->date('end_date')->nullable(); // auto-computed on confirm: start + duration_days

            // Pricing snapshot (locked at booking time)
            $table->decimal('price_amount', 8, 2);
            $table->char('price_currency', 3)->default('GBP');

            // Payment
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');
            $table->string('stripe_checkout_session_id', 255)->nullable();
            $table->string('stripe_payment_intent_id', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('receipt_url', 500)->nullable();

            // Booking lifecycle
            $table->enum('booking_status', [
                'pending_payment',  // awaiting Stripe payment
                'pending_review',   // paid, waiting for admin to review (max 24h)
                'confirmed',        // admin approved, scheduled
                'active',           // ad is currently running
                'completed',        // campaign ended
                'cancelled',        // advertiser or admin cancelled
                'rejected',         // admin rejected (with reason)
            ])->default('pending_payment');

            // Admin workflow
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('admin_notes')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['booking_status', 'start_date']);
            $table->index(['advertiser_email']);
            $table->index('stripe_checkout_session_id');
        });

        // ── Seed default packages ──────────────────────────────────────────
        $now = now();

        DB::table('ad_packages')->insert([
            // ── Website ───────────────────────────────────────────────────
            [
                'slug'          => 'homepage-leaderboard',
                'name'          => 'Homepage Leaderboard',
                'tagline'       => 'Maximum visibility above the fold',
                'description'   => 'Prime position 970×90 banner displayed at the top of every page, seen by all visitors before they scroll.',
                'category'      => 'website',
                'placement'     => 'homepage_leaderboard',
                'platform'      => null,
                'price_amount'  => 299.00,
                'price_currency'=> 'GBP',
                'duration_days' => 7,
                'dimensions'    => '970×90',
                'is_featured'   => false,
                'is_active'     => true,
                'sort_order'    => 10,
                'icon_emoji'    => '🖥️',
                'features'      => json_encode([
                    'Displayed on all public pages',
                    '970×90 desktop + 320×50 mobile',
                    'Click-through to your URL',
                    '7 days live',
                    'Impression report included',
                ]),
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'slug'          => 'article-sidebar-mpu',
                'name'          => 'Article Sidebar MPU',
                'tagline'       => 'Engage readers in-content',
                'description'   => '300×250 medium rectangle displayed in the sticky sidebar next to every article — the most widely read placement.',
                'category'      => 'website',
                'placement'     => 'article_sidebar',
                'platform'      => null,
                'price_amount'  => 149.00,
                'price_currency'=> 'GBP',
                'duration_days' => 7,
                'dimensions'    => '300×250',
                'is_featured'   => false,
                'is_active'     => true,
                'sort_order'    => 20,
                'icon_emoji'    => '📰',
                'features'      => json_encode([
                    'Shown alongside editorial content',
                    '300×250 standard IAB format',
                    'Click-through to your URL',
                    '7 days live',
                    'Impression report included',
                ]),
                'created_at'    => $now,
                'updated_at'    => $now,
            ],

            // ── Social ────────────────────────────────────────────────────
            [
                'slug'          => 'facebook-post',
                'name'          => 'Facebook Sponsored Post',
                'tagline'       => 'Reach our 50k+ Facebook followers',
                'description'   => 'A dedicated branded post published on CNI News Network Facebook page on your chosen date.',
                'category'      => 'social',
                'placement'     => 'facebook_post',
                'platform'      => 'facebook',
                'price_amount'  => 99.00,
                'price_currency'=> 'GBP',
                'duration_days' => 1,
                'dimensions'    => null,
                'is_featured'   => false,
                'is_active'     => true,
                'sort_order'    => 30,
                'icon_emoji'    => '📘',
                'features'      => json_encode([
                    '1 dedicated Facebook post',
                    'Branded image + caption we write for you',
                    'Link to your website / product',
                    'Posted at peak engagement time',
                    'Stays on our page permanently',
                ]),
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'slug'          => 'youtube-mention',
                'name'          => 'YouTube Sponsored Mention',
                'tagline'       => 'Endorsed in our next news video',
                'description'   => 'Your brand is mentioned and linked in the description of our next YouTube video, reaching our subscriber base.',
                'category'      => 'social',
                'placement'     => 'youtube_mention',
                'platform'      => 'youtube',
                'price_amount'  => 199.00,
                'price_currency'=> 'GBP',
                'duration_days' => 1,
                'dimensions'    => null,
                'is_featured'   => false,
                'is_active'     => true,
                'sort_order'    => 40,
                'icon_emoji'    => '▶️',
                'features'      => json_encode([
                    'Verbal mention in next published video',
                    'Link in video description + pinned comment',
                    'Permanent on our channel',
                    'Reaches all subscribers',
                    'Brief pre-approved by you',
                ]),
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'slug'          => 'instagram-story',
                'name'          => 'Instagram Story',
                'tagline'       => 'Full-screen story with swipe-up link',
                'description'   => 'A 24-hour branded Instagram story posted on our account, with a direct swipe-up link to your page.',
                'category'      => 'social',
                'placement'     => 'instagram_story',
                'platform'      => 'instagram',
                'price_amount'  => 79.00,
                'price_currency'=> 'GBP',
                'duration_days' => 1,
                'dimensions'    => null,
                'is_featured'   => false,
                'is_active'     => true,
                'sort_order'    => 50,
                'icon_emoji'    => '📸',
                'features'      => json_encode([
                    '24-hour Instagram story',
                    'Full-screen branded visual',
                    'Swipe-up / link sticker to your URL',
                    'Creative designed by our team',
                    'Reach our Instagram audience',
                ]),
                'created_at'    => $now,
                'updated_at'    => $now,
            ],

            // ── Bundles ───────────────────────────────────────────────────
            [
                'slug'          => 'social-blast',
                'name'          => 'Social Media Blast',
                'tagline'       => 'Hit all platforms in one go',
                'description'   => 'Post on Facebook, YouTube description, and Instagram story simultaneously on the same day.',
                'category'      => 'bundle',
                'placement'     => 'all_social',
                'platform'      => null,
                'price_amount'  => 299.00,
                'price_currency'=> 'GBP',
                'duration_days' => 1,
                'dimensions'    => null,
                'is_featured'   => true,
                'is_active'     => true,
                'sort_order'    => 60,
                'icon_emoji'    => '🚀',
                'features'      => json_encode([
                    'Facebook post + YouTube mention + Instagram story',
                    'Consistent branded messaging across platforms',
                    'Posted same day',
                    'Brief approved by you before posting',
                    'Save £78 vs. booking separately',
                ]),
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'slug'          => 'premium-bundle',
                'name'          => 'Premium Bundle',
                'tagline'       => 'Website + Social — maximum reach',
                'description'   => 'Homepage leaderboard for a week plus a full social media blast. The complete CNI advertising package.',
                'category'      => 'bundle',
                'placement'     => 'all',
                'platform'      => null,
                'price_amount'  => 549.00,
                'price_currency'=> 'GBP',
                'duration_days' => 7,
                'dimensions'    => null,
                'is_featured'   => true,
                'is_active'     => true,
                'sort_order'    => 70,
                'icon_emoji'    => '⭐',
                'features'      => json_encode([
                    'Homepage leaderboard for 7 days',
                    'Facebook + YouTube + Instagram posts',
                    'Dedicated account manager',
                    'Campaign performance report',
                    'Save £148 vs. booking separately',
                ]),
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_bookings');
        Schema::dropIfExists('ad_packages');
    }
};
