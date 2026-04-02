<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social Hub Migration
 *
 * Three tables:
 *
 * 1. social_accounts
 *    Stores one row per connected platform account (Facebook Page, Instagram
 *    Business Account, YouTube Channel, TikTok Account, Twitter/X Account).
 *    Tokens are stored encrypted. Token lifecycle per platform:
 *      - Facebook:  Page Access Token never expires (once exchanged properly)
 *      - Instagram: Same token as Facebook Page (never expires)
 *      - YouTube:   access_token expires 1hr; refresh_token lasts indefinitely
 *      - TikTok:    access_token expires 24hrs; refresh_token expires 365 days
 *      - Twitter/X: OAuth 1.0a tokens don't expire (rotate every 90 days manually)
 *
 * 2. social_posts
 *    One row per scheduled/sent post. Tracks the full lifecycle of a post
 *    from draft → queued → publishing → published / failed.
 *    Linked to an article (optional) and one or more social accounts.
 *    A single article publish creates one social_post row per platform.
 *
 * 3. social_feed_items
 *    Inbound: stores content pulled FROM social platforms INTO the website.
 *    Used to display a unified social feed on the homepage.
 *    YouTube videos are polled via playlistItems.list (1 quota unit).
 *    Facebook/Instagram posts pulled via /{page_id}/feed endpoint.
 *    TikTok has no public read API — items added manually or via webhook.
 *    Twitter/X read requires paid tier — not used for inbound.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── social_accounts ───────────────────────────────────────────────
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();

            // Which CNI channel this account belongs to
            $table->unsignedBigInteger('channel_id');

            // Which staff user connected/owns this account
            $table->unsignedBigInteger('connected_by_user_id')->nullable();

            // ── Platform identifier ───────────────────────────────────────
            $table->enum('platform', [
                'facebook',   // Facebook Page
                'instagram',  // Instagram Business/Creator Account
                'youtube',    // YouTube Channel
                'tiktok',     // TikTok Account
                'twitter',    // Twitter / X Account
            ]);

            // ── Account identity ──────────────────────────────────────────
            // Human-readable name shown in admin UI
            // e.g. "CNI News UK", "CNI News (@cninewsuk)"
            $table->string('account_name', 255);

            // Platform's own ID for this account:
            //   Facebook:  page_id  (e.g. "546349135390552")
            //   Instagram: ig_user_id (numeric, e.g. "17841405309211844")
            //   YouTube:   channel_id (e.g. "UCxxxxxxxxxxxxxxxxxxxxxxxx")
            //   TikTok:    open_id (returned by OAuth, e.g. "abc123")
            //   Twitter:   user_id (numeric string, e.g. "1234567890")
            $table->string('platform_account_id', 255);

            // Username / handle for display only
            // e.g. "@cninewsuk", "CNI News"
            $table->string('platform_username', 255)->nullable();

            // Profile picture URL from the platform (cached for UI display)
            $table->string('profile_picture_url', 500)->nullable();

            // ── Tokens ────────────────────────────────────────────────────
            // All tokens stored encrypted using Laravel's encrypt() helper.
            // Decrypt with decrypt($value) before use.

            // Primary access token:
            //   Facebook:  Never-expiring Page Access Token
            //   Instagram: Never-expiring Page/IG Access Token (same as FB)
            //   YouTube:   Short-lived access token (1hr), refreshed automatically
            //   TikTok:    Short-lived access token (24hr), refreshed automatically
            //   Twitter:   OAuth 1.0a access_token (doesn't expire)
            $table->text('access_token_encrypted');

            // Refresh token (NULL for Facebook/Instagram/Twitter):
            //   YouTube: Long-lived refresh token (indefinite, stored once at OAuth)
            //   TikTok:  Refresh token, expires after 365 days
            $table->text('refresh_token_encrypted')->nullable();

            // When the access_token expires (NULL = never expires):
            //   Facebook:  NULL (never expires)
            //   Instagram: NULL (never expires)
            //   YouTube:   1 hour from issue, updated after each refresh
            //   TikTok:    24 hours from issue, updated after each refresh
            //   Twitter:   NULL (doesn't expire)
            $table->timestamp('token_expires_at')->nullable();

            // When the refresh_token itself expires (NULL = doesn't apply):
            //   TikTok: 365 days from OAuth — must re-auth after this
            $table->timestamp('refresh_token_expires_at')->nullable();

            // ── Platform-specific extra data ──────────────────────────────
            // Stores platform-specific metadata as JSON. Examples:
            //   Facebook:  { "page_id": "...", "page_category": "..." }
            //   Instagram: { "ig_user_id": "...", "followers_count": 1234 }
            //   YouTube:   { "uploads_playlist_id": "UU...", "quota_used_today": 450 }
            //   TikTok:    { "open_id": "...", "union_id": "...", "verified_domain": "cninews.co.uk" }
            //   Twitter:   { "oauth_token_secret": "..." } (encrypted separately)
            $table->json('platform_meta')->nullable();

            // ── Twitter / X specific OAuth 1.0a secrets ──────────────────
            // Twitter needs 4 credentials for OAuth 1.0a signing:
            //   api_key, api_secret (app-level) → stored in .env
            //   access_token, access_token_secret (user-level) → stored here
            // access_token is in access_token_encrypted above.
            // access_token_secret stored separately for clarity.
            $table->text('oauth_token_secret_encrypted')->nullable();

            // ── Status ────────────────────────────────────────────────────
            $table->boolean('is_active')->default(true);

            // Reason for deactivation (token expired, permission revoked, etc.)
            $table->string('deactivation_reason', 255)->nullable();

            // When last successfully used
            $table->timestamp('last_used_at')->nullable();

            // When last token refresh succeeded
            $table->timestamp('last_refreshed_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['channel_id', 'platform', 'is_active']);
            $table->unique(['channel_id', 'platform', 'platform_account_id']);

            $table->foreign('channel_id')
                  ->references('id')->on('channels')->cascadeOnDelete();
            $table->foreign('connected_by_user_id')
                  ->references('id')->on('users')->nullOnDelete();
        });

        // ── social_posts ──────────────────────────────────────────────────
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('channel_id');

            // The social account this post goes to
            $table->unsignedBigInteger('social_account_id');

            // The article this post was generated from (NULL = manual post)
            $table->unsignedBigInteger('article_id')->nullable();

            // The user who created/triggered this post
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            // ── Post content ──────────────────────────────────────────────

            // Platform shorthand (denormalised from social_account for quick filtering)
            $table->enum('platform', [
                'facebook', 'instagram', 'youtube', 'tiktok', 'twitter',
            ]);

            // Text content of the post.
            //   Facebook:  message field (up to ~63,206 chars)
            //   Instagram: caption field (up to 2,200 chars)
            //   YouTube:   video description (up to 5,000 chars)
            //   TikTok:    title field (up to 2,200 chars)
            //   Twitter:   text field (up to 280 chars)
            $table->text('content_text')->nullable();

            // URL to link/share (used as Facebook `link`, embedded in others)
            $table->string('link_url', 500)->nullable();

            // ── Media ─────────────────────────────────────────────────────
            // Reference to a media_asset for image/video attachment
            $table->unsignedBigInteger('media_asset_id')->nullable();

            // For platforms that need a public URL (TikTok PULL_FROM_URL,
            // Instagram video_url, YouTube upload URL).
            // This is the publicly accessible URL of the media file.
            $table->string('media_public_url', 500)->nullable();

            // ── Platform-specific post options ────────────────────────────
            // Stores platform-specific overrides as JSON. Examples:
            //   Facebook:  { "published": true }
            //   Instagram: { "media_type": "REELS", "share_to_feed": true }
            //   YouTube:   { "title": "...", "tags": ["news","pakistan"], "category_id": "25",
            //                "privacy_status": "public", "made_for_kids": false }
            //   TikTok:    { "privacy_level": "PUBLIC_TO_EVERYONE",
            //                "disable_duet": false, "disable_comment": false,
            //                "disable_stitch": false, "video_cover_timestamp_ms": 0 }
            //   Twitter:   { "media_ids": ["..."] }
            $table->json('platform_options')->nullable();

            // ── Scheduling ────────────────────────────────────────────────
            $table->enum('post_type', [
                'immediate',  // post as soon as job runs
                'scheduled',  // post at scheduled_at datetime
                'draft',      // saved but not queued
            ])->default('immediate');

            // When this post should be published (NULL = ASAP)
            $table->timestamp('scheduled_at')->nullable();

            // ── Status lifecycle ──────────────────────────────────────────
            // draft       → not yet queued
            // queued      → pushed to Laravel queue, waiting to run
            // publishing  → job picked up, API call in progress
            // published   → successfully posted to platform
            // failed      → API call failed, see error fields
            // cancelled   → manually cancelled before it ran
            $table->enum('status', [
                'draft',
                'queued',
                'publishing',
                'published',
                'failed',
                'cancelled',
            ])->default('draft');

            // When the post actually went live on the platform
            $table->timestamp('published_at')->nullable();

            // ── Platform response data ────────────────────────────────────
            // ID returned by the platform after successful post:
            //   Facebook:  post_id  e.g. "546349135390552_1116691191689674"
            //   Instagram: media_id e.g. "17918920912340654"
            //   YouTube:   video_id e.g. "dQw4w9WgXcQ"
            //   TikTok:    publish_id (during upload) then post_id after moderation
            //   Twitter:   tweet_id  e.g. "1234567890123456789"
            $table->string('platform_post_id', 255)->nullable();

            // URL to view the post on the platform (populated after publishing)
            $table->string('platform_post_url', 500)->nullable();

            // ── Error handling ────────────────────────────────────────────
            // How many times this post has been attempted
            $table->unsignedTinyInteger('attempt_count')->default(0);

            // Max retries before marking as permanently failed
            // Set to 3 by default — covers transient API errors
            $table->unsignedTinyInteger('max_attempts')->default(3);

            // The error message from the last failed attempt
            $table->text('error_message')->nullable();

            // Structured error data from the platform API (for debugging):
            //   Facebook: { "type": "OAuthException", "code": 190, "error_subcode": 460 }
            //   YouTube:  { "reason": "quotaExceeded", "domain": "youtube.quota" }
            //   TikTok:   { "code": "spam_risk_too_many_posts", "log_id": "..." }
            //   Twitter:  { "title": "Forbidden", "status": 403 }
            $table->json('error_data')->nullable();

            // When to next retry a failed post (exponential backoff)
            // Calculated as: now() + (2^attempt_count) minutes
            $table->timestamp('retry_after')->nullable();

            // ── Queue job tracking ────────────────────────────────────────
            // Laravel queue job ID for tracking/cancellation
            $table->string('queue_job_id', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->index(['channel_id', 'platform', 'status']);
            $table->index(['social_account_id', 'status']);
            $table->index(['article_id']);
            $table->index(['status', 'scheduled_at']); // for scheduler query
            $table->index(['status', 'retry_after']);   // for retry query

            $table->foreign('channel_id')
                  ->references('id')->on('channels')->cascadeOnDelete();
            $table->foreign('social_account_id')
                  ->references('id')->on('social_accounts')->cascadeOnDelete();
            $table->foreign('article_id')
                  ->references('id')->on('articles')->nullOnDelete();
            $table->foreign('media_asset_id')
                  ->references('id')->on('media_assets')->nullOnDelete();
            $table->foreign('created_by_user_id')
                  ->references('id')->on('users')->nullOnDelete();
        });

        // ── social_feed_items ─────────────────────────────────────────────
        // Inbound: content pulled FROM platforms and displayed on the website.
        // Populated by the SocialIngestCommand (runs every 30 minutes via cron).
        //
        // What gets ingested per platform:
        //   YouTube:   Latest videos from uploads playlist (free, 1 quota unit/call)
        //   Facebook:  Latest posts from /{page_id}/feed (free)
        //   Instagram: Latest media from /{ig_user_id}/media (free)
        //   TikTok:    No read API — items inserted manually or via webhook stub
        //   Twitter:   NOT ingested (requires paid API) — use embed widget instead
        Schema::create('social_feed_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('channel_id');

            // Which connected account this item came from
            $table->unsignedBigInteger('social_account_id');

            // ── Platform identity ─────────────────────────────────────────
            $table->enum('platform', [
                'facebook', 'instagram', 'youtube', 'tiktok', 'twitter',
            ]);

            // The platform's own ID for this item (unique per platform):
            //   Facebook:  post_id    e.g. "546349135390552_1116691191689674"
            //   Instagram: media_id   e.g. "17918920912340654"
            //   YouTube:   video_id   e.g. "dQw4w9WgXcQ"
            //   TikTok:    video_id   from webhook/manual
            //   Twitter:   tweet_id   (not used currently)
            $table->string('platform_item_id', 255);

            // ── Content type ──────────────────────────────────────────────
            $table->enum('content_type', [
                'post',    // Facebook text/link post
                'photo',   // Facebook/Instagram image post
                'video',   // Facebook/Instagram/TikTok video
                'reel',    // Instagram Reel
                'youtube', // YouTube video
                'tweet',   // Twitter/X post (reserved for future)
            ]);

            // ── Content fields ────────────────────────────────────────────
            // Text content of the post/caption
            $table->text('caption')->nullable();

            // Direct URL to the media (image or video):
            //   Facebook:  full_picture field from feed API
            //   Instagram: media_url field (image) or thumbnail_url (video)
            //   YouTube:   thumbnail URL (mqdefault.jpg from YouTube CDN)
            //   TikTok:    cover image URL
            $table->string('media_url', 500)->nullable();

            // Thumbnail specifically for videos (separate from media_url)
            $table->string('thumbnail_url', 500)->nullable();

            // Permanent link to the post on the platform
            //   Facebook:  permalink_url from feed API
            //   Instagram: permalink field from media API
            //   YouTube:   https://youtube.com/watch?v={video_id}
            //   TikTok:    https://tiktok.com/@{username}/video/{video_id}
            $table->string('permalink', 500)->nullable();

            // Title (YouTube video title, TikTok caption title)
            $table->string('title', 500)->nullable();

            // Duration in seconds (YouTube/video only)
            $table->unsignedInteger('duration_seconds')->nullable();

            // ── Engagement metrics ────────────────────────────────────────
            // Cached engagement counts (updated periodically by ingest command)
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->unsignedBigInteger('comments_count')->default(0);
            $table->unsignedBigInteger('shares_count')->default(0);

            // ── Timing ────────────────────────────────────────────────────
            // When this item was originally posted on the platform
            $table->timestamp('posted_at')->nullable();

            // ── Display control ───────────────────────────────────────────
            // Admin can hide specific items from the homepage feed
            $table->boolean('is_visible')->default(true);

            // Pin item to top of feed (e.g. pinned YouTube video)
            $table->boolean('is_pinned')->default(false);

            // Sort order override (lower = higher in feed)
            $table->unsignedSmallInteger('display_order')->default(0);

            // ── Raw API response ──────────────────────────────────────────
            // Store the raw API response so we can re-parse without re-fetching.
            // Also useful for debugging ingestion issues.
            $table->json('raw_data')->nullable();

            $table->timestamps();

            // Prevent duplicate ingestion of the same item
            $table->unique(['platform', 'platform_item_id']);

            // Indexes for homepage feed query
            $table->index(['channel_id', 'platform', 'is_visible', 'posted_at']);
            $table->index(['is_visible', 'is_pinned', 'posted_at']);
            $table->index(['social_account_id']);

            $table->foreign('channel_id')
                  ->references('id')->on('channels')->cascadeOnDelete();
            $table->foreign('social_account_id')
                  ->references('id')->on('social_accounts')->cascadeOnDelete();
        });

        // ── youtube_quota_usage ───────────────────────────────────────────
        // Tracks daily YouTube Data API v3 quota consumption.
        // Default free quota: 10,000 units/day (resets midnight PT).
        // Costs per operation:
        //   videos.insert (upload):     1,600 units
        //   playlistItems.list (feed):      1 unit
        //   videos.list (details):          1 unit
        //   channels.list (channel info):   1 unit
        // At 10,000 units/day we can do 6 uploads + ~hundreds of reads.
        Schema::create('youtube_quota_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('social_account_id');

            // The date this quota usage applies to (Pacific Time date)
            $table->date('quota_date');

            // Running total of units consumed today
            $table->unsignedInteger('units_used')->default(0);

            // Breakdown by operation type for debugging
            $table->unsignedSmallInteger('uploads_count')->default(0);
            $table->unsignedSmallInteger('reads_count')->default(0);

            $table->timestamps();

            $table->unique(['social_account_id', 'quota_date']);
            $table->index(['quota_date', 'social_account_id']);

            $table->foreign('social_account_id')
                  ->references('id')->on('social_accounts')->cascadeOnDelete();
        });

        // ── tiktok_publish_status ─────────────────────────────────────────
        // TikTok posts go through an async multi-stage pipeline:
        //   CREATED → PROCESSING → PUBLISH_COMPLETE | FAILED
        // We must poll /v2/post/publish/status/fetch/ until complete.
        // This table tracks the polling state separately from social_posts
        // so we don't pollute the main posts table with TikTok internals.
        Schema::create('tiktok_publish_status', function (Blueprint $table) {
            $table->id();

            // Reference to the social_post being tracked
            $table->unsignedBigInteger('social_post_id');

            // TikTok's publish_id returned by /v2/post/publish/video/init/
            // e.g. "v_pub_url~v2.123456789"
            $table->string('publish_id', 255);

            // Current status from TikTok's status API:
            //   CREATED          — init accepted, upload not started
            //   PROCESSING       — video being processed by TikTok
            //   PUBLISH_COMPLETE — live on TikTok, post_id available
            //   FAILED           — processing failed, see fail_reason
            $table->string('tiktok_status', 50)->default('CREATED');

            // Error code from TikTok if status = FAILED
            // e.g. "publish_video_FAILED_due_to_video_too_long"
            $table->string('fail_reason', 255)->nullable();

            // The final TikTok post_id (only available after PUBLISH_COMPLETE
            // AND after TikTok's moderation process finishes)
            $table->string('tiktok_post_id', 255)->nullable();

            // How many times we've polled status
            $table->unsignedSmallInteger('poll_count')->default(0);

            // When to poll next (exponential backoff: 30s, 1m, 2m, 4m...)
            $table->timestamp('next_poll_at')->nullable();

            // When polling should be abandoned (15 minutes max per TikTok docs)
            $table->timestamp('abandon_after')->nullable();

            $table->timestamps();

            $table->unique('social_post_id');
            $table->index(['tiktok_status', 'next_poll_at']);

            $table->foreign('social_post_id')
                  ->references('id')->on('social_posts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_publish_status');
        Schema::dropIfExists('youtube_quota_usage');
        Schema::dropIfExists('social_feed_items');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_accounts');
    }
};
