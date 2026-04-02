<?php

namespace App\Services\Social;

use App\Jobs\SocialPublishJob;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use Illuminate\Support\Facades\DB;

/**
 * SocialPostService
 *
 * The central brain of the Social Hub.
 * Creates, validates, schedules and dispatches social posts.
 *
 * Two entry points:
 *
 *   createFromArticle() — admin publishes an article and checks
 *     "also share on social". Builds platform-specific content
 *     automatically from the article's title, summary, image and URL.
 *
 *   createManual() — admin writes a custom post in the Social Hub
 *     composer, picks platforms, sets schedule, and submits.
 *
 * Both paths:
 *   1. Validate each selected account is active
 *   2. Build content per platform (character limits differ)
 *   3. Insert one social_posts row per platform
 *   4. If immediate: dispatch SocialPublishJob to the 'social' queue
 *   5. If scheduled: leave status='queued', scheduler picks it up later
 *
 * Character limits enforced:
 *   Facebook:  63,206 chars (message field) — we use 2,000 max for readability
 *   Instagram: 2,200 chars (caption)
 *   YouTube:   5,000 chars (description) — requires video, handled separately
 *   TikTok:    2,200 chars (title)
 *   Twitter:   280 chars
 */
class SocialPostService
{
    // Safe content limits per platform (shorter than API max for readability)
    private const LIMITS = [
        'facebook'  => 2000,
        'instagram' => 2200,
        'youtube'   => 5000,
        'tiktok'    => 2200,
        'twitter'   => 280,
    ];

    /**
     * Create social posts for an article across multiple platforms.
     * Called when an article is published and "Share on social" is checked.
     *
     * @param  Article  $article
     * @param  array    $accountIds  — social_account IDs to post to
     * @param  array    $options     — per-platform overrides keyed by platform name
     *                                e.g. ['twitter' => ['text' => 'Custom tweet text']]
     * @param  string|null $scheduledAt — ISO 8601 datetime or null for immediate
     * @return SocialPost[]  — array of created posts
     */
    public function createFromArticle(
        Article   $article,
        array     $accountIds,
        array     $options     = [],
        ?string   $scheduledAt = null,
        int       $createdByUserId = 0
    ): array {
        $accounts = SocialAccount::whereIn('id', $accountIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($accounts->isEmpty()) {
            throw new \InvalidArgumentException('No active social accounts found for the given IDs.');
        }

        // Load article with everything we need to build content
        $article->loadMissing(['translations', 'mainCategory', 'featuredImage']);
        $translation = $article->translations->first();
        $title       = $translation?->title ?? $article->slug;
        $summary     = $translation?->summary ?? '';
        $articleUrl  = $this->buildArticleUrl($article);
        $imageUrl    = $this->resolveArticleImageUrl($article);

        $posts = [];

        DB::transaction(function () use (
            $accounts, $article, $title, $summary, $articleUrl, $imageUrl,
            $options, $scheduledAt, $createdByUserId, &$posts
        ) {
            foreach ($accounts as $account) {
                $platformOptions  = $options[$account->platform] ?? [];
                $postType         = $scheduledAt ? 'scheduled' : 'immediate';
                $scheduledCarbon  = $scheduledAt ? \Carbon\Carbon::parse($scheduledAt) : null;

                // Build platform-specific content
                $content = $this->buildContentForPlatform(
                    $account->platform,
                    $title,
                    $summary,
                    $articleUrl,
                    $platformOptions
                );

                $post = SocialPost::create([
                    'channel_id'          => $account->channel_id,
                    'social_account_id'   => $account->id,
                    'article_id'          => $article->id,
                    'created_by_user_id'  => $createdByUserId ?: null,
                    'platform'            => $account->platform,
                    'content_text'        => $content['text'],
                    'link_url'            => $content['link_url'] ?? $articleUrl,
                    'media_public_url'    => $content['media_url'] ?? $imageUrl,
                    'platform_options'    => array_merge(
                        $this->defaultPlatformOptions($account->platform, $title),
                        $platformOptions
                    ),
                    'post_type'           => $postType,
                    'scheduled_at'        => $scheduledCarbon,
                    'status'              => 'queued',
                ]);

                // Dispatch immediately if not scheduled
                if ($postType === 'immediate') {
                    SocialPublishJob::dispatch($post->id);
                }

                $posts[] = $post;
            }
        });

        AuditLog::log('social_posts_created', 'article', $article->id, null, [
            'platforms'  => collect($posts)->pluck('platform')->all(),
            'article'    => $title,
            'scheduled'  => $scheduledAt,
        ]);

        return $posts;
    }

    /**
     * Create a manual social post (not linked to an article).
     * Used from the Social Hub composer in the admin panel.
     *
     * @param  array  $data  — validated request data (see SocialPostAdminController)
     * @return SocialPost[]
     */
    public function createManual(array $data, int $createdByUserId): array
    {
        $accountIds  = $data['account_ids'];
        $text        = $data['text'] ?? '';
        $linkUrl     = $data['link_url'] ?? null;
        $mediaUrl    = $data['media_url'] ?? null;
        $scheduledAt = isset($data['scheduled_at']) ? \Carbon\Carbon::parse($data['scheduled_at']) : null;
        $postType    = $scheduledAt ? 'scheduled' : 'immediate';

        $accounts = SocialAccount::whereIn('id', $accountIds)
            ->where('is_active', true)
            ->get();

        if ($accounts->isEmpty()) {
            throw new \InvalidArgumentException('No active social accounts found.');
        }

        $posts = [];

        DB::transaction(function () use (
            $accounts, $text, $linkUrl, $mediaUrl,
            $scheduledAt, $postType, $createdByUserId, $data, &$posts
        ) {
            foreach ($accounts as $account) {
                $platformOptions = $data['platform_options'][$account->platform] ?? [];

                // Trim content to platform limit
                $trimmedText = $this->trimToPlatformLimit($account->platform, $text);

                $post = SocialPost::create([
                    'channel_id'         => $account->channel_id,
                    'social_account_id'  => $account->id,
                    'article_id'         => null,
                    'created_by_user_id' => $createdByUserId,
                    'platform'           => $account->platform,
                    'content_text'       => $trimmedText,
                    'link_url'           => $linkUrl,
                    'media_public_url'   => $mediaUrl,
                    'platform_options'   => array_merge(
                        $this->defaultPlatformOptions($account->platform, $trimmedText),
                        $platformOptions
                    ),
                    'post_type'          => $postType,
                    'scheduled_at'       => $scheduledAt,
                    'status'             => 'queued',
                ]);

                if ($postType === 'immediate') {
                    SocialPublishJob::dispatch($post->id);
                }

                $posts[] = $post;
            }
        });

        return $posts;
    }

    /**
     * Cancel a queued or scheduled post before it runs.
     *
     * @throws \InvalidArgumentException if post cannot be cancelled
     */
    public function cancel(SocialPost $post): void
    {
        if (!in_array($post->status, ['queued', 'draft', 'failed'])) {
            throw new \InvalidArgumentException(
                "Cannot cancel a post with status '{$post->status}'. Only queued, draft or failed posts can be cancelled."
            );
        }

        $post->update(['status' => 'cancelled']);

        AuditLog::log('social_post_cancelled', 'social_post', $post->id, null, [
            'platform' => $post->platform,
        ]);
    }

    /**
     * Manually re-queue a failed post for immediate retry.
     *
     * @throws \InvalidArgumentException if post cannot be retried
     */
    public function retry(SocialPost $post): void
    {
        if ($post->status !== 'failed') {
            throw new \InvalidArgumentException(
                "Only failed posts can be retried. Current status: {$post->status}"
            );
        }

        $post->update([
            'status'        => 'queued',
            'retry_after'   => null,
            'error_message' => null,
        ]);

        SocialPublishJob::dispatch($post->id);

        AuditLog::log('social_post_retried', 'social_post', $post->id, null, [
            'platform' => $post->platform,
        ]);
    }

    // ── Content builders ──────────────────────────────────────────────────

    /**
     * Build platform-specific post content from article data.
     * Each platform has different optimal format and character limits.
     */
    private function buildContentForPlatform(
        string $platform,
        string $title,
        string $summary,
        string $articleUrl,
        array  $overrides = []
    ): array {
        // Allow full override of text from admin
        if (!empty($overrides['text'])) {
            return [
                'text'     => $this->trimToPlatformLimit($platform, $overrides['text']),
                'link_url' => $articleUrl,
                'media_url'=> $overrides['media_url'] ?? null,
            ];
        }

        $text = match ($platform) {
            // Facebook: headline + summary + URL (FB auto-generates link preview)
            'facebook' => $this->buildFacebookText($title, $summary, $articleUrl),

            // Instagram: headline + summary + "link in bio" CTA (no clickable links in captions)
            'instagram' => $this->buildInstagramCaption($title, $summary),

            // YouTube: title goes in platform_options, description here
            'youtube' => $summary ?: $title,

            // TikTok: title + hashtags (goes in platform_options title field)
            'tiktok' => $this->buildTikTokTitle($title),

            // Twitter: headline + short URL (280 char limit)
            'twitter' => $this->buildTweetText($title, $articleUrl),

            default => $title,
        };

        return [
            'text'      => $this->trimToPlatformLimit($platform, $text),
            'link_url'  => in_array($platform, ['facebook', 'twitter']) ? $articleUrl : null,
            'media_url' => $overrides['media_url'] ?? null,
        ];
    }

    private function buildFacebookText(string $title, string $summary, string $url): string
    {
        // Facebook generates a link preview from the URL so we just need good text
        $text = "📰 {$title}";
        if ($summary) {
            $text .= "\n\n" . $summary;
        }
        $text .= "\n\n🔗 Read more: {$url}";
        return $text;
    }

    private function buildInstagramCaption(string $title, string $summary): string
    {
        // Instagram captions cannot have clickable links — direct to bio
        $caption = $title;
        if ($summary) {
            $caption .= "\n\n" . $summary;
        }
        $caption .= "\n\n🔗 Link in bio for full story.\n\n";
        $caption .= "#CNINews #Pakistan #Kashmir #BreakingNews #News";
        return $caption;
    }

    private function buildTikTokTitle(string $title): string
    {
        // TikTok title includes hashtags, max 2200 chars
        return "{$title} #CNINews #Pakistan #News #BreakingNews";
    }

    private function buildTweetText(string $title, string $url): string
    {
        // Twitter: 280 chars total. URLs count as 23 chars regardless of length.
        // So we have 280 - 23 - 1 (space) = 256 chars for the text.
        $maxText = 256;
        $text    = strlen($title) > $maxText
            ? substr($title, 0, $maxText - 3) . '...'
            : $title;

        return "{$text} {$url}";
    }

    private function trimToPlatformLimit(string $platform, string $text): string
    {
        $limit = self::LIMITS[$platform] ?? 2000;
        if (strlen($text) <= $limit) return $text;
        return substr($text, 0, $limit - 3) . '...';
    }

    /**
     * Default platform_options for each platform.
     * These are merged with any admin overrides.
     */
    private function defaultPlatformOptions(string $platform, string $titleOrText): array
    {
        return match ($platform) {
            'youtube' => [
                'title'          => substr($titleOrText, 0, 100),
                'category_id'    => '25', // News & Politics
                'privacy_status' => 'public',
                'tags'           => ['CNI News', 'Pakistan', 'Kashmir', 'News'],
            ],
            'tiktok' => [
                'title'           => substr($titleOrText, 0, 2200),
                'privacy_level'   => 'PUBLIC_TO_EVERYONE',
                'disable_duet'    => false,
                'disable_comment' => false,
                'disable_stitch'  => false,
            ],
            'instagram' => [
                'media_type'    => 'IMAGE',
                'share_to_feed' => true,
            ],
            default => [],
        };
    }

    // ── URL & image helpers ───────────────────────────────────────────────

    private function buildArticleUrl(Article $article): string
    {
        $appUrl = rtrim(config('app.url'), '/');
        return "{$appUrl}/article/{$article->slug}";
    }

    private function resolveArticleImageUrl(Article $article): ?string
    {
        $image = $article->featuredImage;
        if (!$image) return null;

        if ($image->original_url) return $image->original_url;

        if ($image->internal_path) {
            return \Illuminate\Support\Facades\Storage::disk($image->disk ?? 'public')
                ->url($image->internal_path);
        }

        return null;
    }
}
