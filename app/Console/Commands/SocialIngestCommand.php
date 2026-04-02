<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Models\SocialFeedItem;
use App\Models\YoutubeQuotaUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SocialIngestCommand
 *
 * Pulls content FROM social platforms INTO the social_feed_items table.
 * Run every 30 minutes via the scheduler.
 *
 * Per platform:
 *
 *   YouTube  — GET /youtube/v3/playlistItems (uploads playlist, 1 unit/call)
 *              Fetches latest 10 videos, upserts into social_feed_items.
 *              Safe: costs only 1 quota unit (not the 1,600 upload cost).
 *
 *   Facebook — GET /{page_id}/feed?fields=id,message,full_picture,permalink_url,created_time
 *              Fetches latest 10 posts. Free, no quota system.
 *              Only pulls posts that have full_picture (no text-only posts in feed).
 *
 *   Instagram — GET /{ig_user_id}/media?fields=id,caption,media_type,media_url,thumbnail_url,permalink,timestamp
 *               Fetches latest 10 media items. Free.
 *
 *   TikTok   — No public read API. Items must be inserted manually or via
 *               webhook (not implemented — TikTok doesn't offer read access).
 *
 *   Twitter  — NOT ingested. Free tier has no read access. Use Twitter's
 *               embedded timeline widget on the frontend instead.
 *
 * Duplicate prevention: UNIQUE constraint on (platform, platform_item_id).
 * New items are INSERT IGNORE / updateOrCreate — safe to run repeatedly.
 */
class SocialIngestCommand extends Command
{
    protected $signature   = 'social:ingest';
    protected $description = 'Pull latest content from connected social platforms into the feed.';

    public function handle(): void
    {
        $accounts = SocialAccount::where('is_active', true)
            ->whereIn('platform', ['youtube', 'facebook', 'instagram'])
            ->get();

        if ($accounts->isEmpty()) {
            $this->line('social:ingest: no active accounts to ingest.');
            return;
        }

        foreach ($accounts as $account) {
            try {
                $count = match ($account->platform) {
                    'youtube'   => $this->ingestYouTube($account),
                    'facebook'  => $this->ingestFacebook($account),
                    'instagram' => $this->ingestInstagram($account),
                    default     => 0,
                };

                if ($count > 0) {
                    $this->line("  ✓ {$account->platform} ({$account->account_name}): {$count} new item(s)");
                }

            } catch (\Throwable $e) {
                Log::error("social:ingest failed for {$account->platform} account {$account->id}", [
                    'error' => $e->getMessage(),
                ]);
                $this->error("  ✗ {$account->platform} ({$account->account_name}): {$e->getMessage()}");
            }
        }
    }

    // ── YouTube Ingest ────────────────────────────────────────────────────

    private function ingestYouTube(SocialAccount $account): int
    {
        // Get the uploads playlist ID (stored in platform_meta at connect time)
        $uploadsPlaylistId = $account->getMeta('uploads_playlist_id');

        // If we don't have it yet, fetch it (costs 1 quota unit)
        if (!$uploadsPlaylistId) {
            $uploadsPlaylistId = $this->fetchYouTubeUploadsPlaylistId($account);
            if (!$uploadsPlaylistId) return 0;
        }

        // Fetch latest 10 videos from the uploads playlist (1 quota unit)
        $apiKey   = config('services.youtube.api_key');
        $response = Http::timeout(15)->get('https://www.googleapis.com/youtube/v3/playlistItems', [
            'part'       => 'snippet',
            'playlistId' => $uploadsPlaylistId,
            'maxResults' => 10,
            'key'        => $apiKey,
        ])->json();

        YoutubeQuotaUsage::consume($account->id, 1, 'read');

        if (isset($response['error'])) {
            throw new \RuntimeException("YouTube ingest error: {$response['error']['message']}");
        }

        $newCount = 0;

        foreach ($response['items'] ?? [] as $item) {
            $snippet = $item['snippet'];
            $videoId = $snippet['resourceId']['videoId'] ?? null;
            if (!$videoId) continue;

            $thumbnail = $snippet['thumbnails']['maxres']['url']
                      ?? $snippet['thumbnails']['high']['url']
                      ?? $snippet['thumbnails']['medium']['url']
                      ?? "https://img.youtube.com/vi/{$videoId}/mqdefault.jpg";

            $created = SocialFeedItem::updateOrCreate(
                [
                    'platform'        => 'youtube',
                    'platform_item_id'=> $videoId,
                ],
                [
                    'channel_id'       => $account->channel_id,
                    'social_account_id'=> $account->id,
                    'content_type'     => 'youtube',
                    'title'            => $snippet['title'] ?? null,
                    'caption'          => $snippet['description'] ?? null,
                    'thumbnail_url'    => $thumbnail,
                    'media_url'        => $thumbnail,
                    'permalink'        => "https://www.youtube.com/watch?v={$videoId}",
                    'posted_at'        => isset($snippet['publishedAt'])
                                         ? \Carbon\Carbon::parse($snippet['publishedAt'])
                                         : null,
                    'is_visible'       => true,
                    'raw_data'         => $snippet,
                ]
            );

            if ($created->wasRecentlyCreated) $newCount++;
        }

        $account->update(['last_used_at' => now()]);
        return $newCount;
    }

    private function fetchYouTubeUploadsPlaylistId(SocialAccount $account): ?string
    {
        $channelId = config('services.youtube.channel_id');
        $apiKey    = config('services.youtube.api_key');

        $response = Http::timeout(15)->get('https://www.googleapis.com/youtube/v3/channels', [
            'part' => 'contentDetails',
            'id'   => $channelId,
            'key'  => $apiKey,
        ])->json();

        YoutubeQuotaUsage::consume($account->id, 1, 'read');

        $playlistId = $response['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;

        if ($playlistId) {
            // Cache it in platform_meta so we don't fetch it again
            $account->setMeta('uploads_playlist_id', $playlistId);
            $account->save();
        }

        return $playlistId;
    }

    // ── Facebook Ingest ───────────────────────────────────────────────────

    private function ingestFacebook(SocialAccount $account): int
    {
        $pageId = $account->platform_account_id;
        $token  = $account->getAccessToken();

        $response = Http::timeout(15)->get("https://graph.facebook.com/v22.0/{$pageId}/feed", [
            'access_token' => $token,
            'fields'       => 'id,message,story,full_picture,permalink_url,created_time,attachments',
            'limit'        => 10,
        ])->json();

        if (isset($response['error'])) {
            // Token invalidated — mark inactive
            if (in_array($response['error']['code'] ?? 0, [190, 200])) {
                $account->update([
                    'is_active'           => false,
                    'deactivation_reason' => \App\Services\Social\FacebookTokenManager::diagnoseError(
                        $response['error']['code'],
                        $response['error']['error_subcode'] ?? null
                    ),
                ]);
            }
            throw new \RuntimeException("Facebook ingest error: {$response['error']['message']}");
        }

        $newCount = 0;

        foreach ($response['data'] ?? [] as $post) {
            // Only ingest posts that have a picture (skip pure text updates)
            $pictureUrl = $post['full_picture'] ?? null;
            $message    = $post['message'] ?? $post['story'] ?? null;

            if (!$message && !$pictureUrl) continue;

            $contentType = $pictureUrl ? 'photo' : 'post';

            $created = SocialFeedItem::updateOrCreate(
                [
                    'platform'         => 'facebook',
                    'platform_item_id' => $post['id'],
                ],
                [
                    'channel_id'       => $account->channel_id,
                    'social_account_id'=> $account->id,
                    'content_type'     => $contentType,
                    'caption'          => $message,
                    'media_url'        => $pictureUrl,
                    'permalink'        => $post['permalink_url'] ?? null,
                    'posted_at'        => isset($post['created_time'])
                                         ? \Carbon\Carbon::parse($post['created_time'])
                                         : null,
                    'is_visible'       => true,
                    'raw_data'         => $post,
                ]
            );

            if ($created->wasRecentlyCreated) $newCount++;
        }

        $account->update(['last_used_at' => now()]);
        return $newCount;
    }

    // ── Instagram Ingest ──────────────────────────────────────────────────

    private function ingestInstagram(SocialAccount $account): int
    {
        $igUserId = $account->platform_account_id;
        $token    = $account->getAccessToken();

        $response = Http::timeout(15)->get("https://graph.instagram.com/v22.0/{$igUserId}/media", [
            'access_token' => $token,
            'fields'       => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp',
            'limit'        => 10,
        ])->json();

        if (isset($response['error'])) {
            if (in_array($response['error']['code'] ?? 0, [190, 200])) {
                $account->update([
                    'is_active'           => false,
                    'deactivation_reason' => 'Instagram token invalidated. Please reconnect.',
                ]);
            }
            throw new \RuntimeException("Instagram ingest error: {$response['error']['message']}");
        }

        $newCount = 0;

        foreach ($response['data'] ?? [] as $media) {
            $mediaType   = strtolower($media['media_type'] ?? 'image');
            $contentType = match ($mediaType) {
                'video'          => 'video',
                'carousel_album' => 'photo',
                default          => 'photo',
            };

            // For Reels (media_type = VIDEO), use thumbnail_url as the preview image
            $mediaUrl     = $media['media_url']     ?? null;
            $thumbnailUrl = $media['thumbnail_url'] ?? $mediaUrl;

            $created = SocialFeedItem::updateOrCreate(
                [
                    'platform'         => 'instagram',
                    'platform_item_id' => $media['id'],
                ],
                [
                    'channel_id'       => $account->channel_id,
                    'social_account_id'=> $account->id,
                    'content_type'     => $contentType,
                    'caption'          => $media['caption'] ?? null,
                    'media_url'        => $mediaUrl,
                    'thumbnail_url'    => $thumbnailUrl,
                    'permalink'        => $media['permalink'] ?? null,
                    'posted_at'        => isset($media['timestamp'])
                                         ? \Carbon\Carbon::parse($media['timestamp'])
                                         : null,
                    'is_visible'       => true,
                    'raw_data'         => $media,
                ]
            );

            if ($created->wasRecentlyCreated) $newCount++;
        }

        $account->update(['last_used_at' => now()]);
        return $newCount;
    }
}
