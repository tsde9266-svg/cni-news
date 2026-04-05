<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportRssFeedsCommand extends Command
{
    protected $signature = 'cni:import-rss
                            {--limit=4 : Max articles to import per category}
                            {--dry-run : Preview without inserting}';

    protected $description = 'Import latest news from CNN (and supplementary sources) into the CMS';

    // ── CNN-first feeds, supplemented by BBC/Sky/Guardian ─────────────────
    private array $feeds = [
        // CNN — primary source
        ['url' => 'http://rss.cnn.com/rss/edition.rss',                    'source' => 'CNN', 'category' => 'world'],
        ['url' => 'http://rss.cnn.com/rss/edition_world.rss',              'source' => 'CNN', 'category' => 'world'],
        ['url' => 'http://rss.cnn.com/rss/edition_us.rss',                 'source' => 'CNN', 'category' => 'uk'],
        ['url' => 'http://rss.cnn.com/rss/cnn_allpolitics.rss',            'source' => 'CNN', 'category' => 'politics'],
        ['url' => 'http://rss.cnn.com/rss/money_news_international.rss',   'source' => 'CNN', 'category' => 'business'],
        ['url' => 'http://rss.cnn.com/rss/edition_technology.rss',         'source' => 'CNN', 'category' => 'technology'],
        ['url' => 'http://rss.cnn.com/rss/edition_sport.rss',              'source' => 'CNN', 'category' => 'sport'],
        ['url' => 'http://rss.cnn.com/rss/edition_entertainment.rss',      'source' => 'CNN', 'category' => 'entertainment'],
        ['url' => 'http://rss.cnn.com/rss/edition_space.rss',              'source' => 'CNN', 'category' => 'science'],

        // Supplementary — fill categories CNN misses
        ['url' => 'https://feeds.bbci.co.uk/news/uk/rss.xml',              'source' => 'BBC News', 'category' => 'uk'],
        ['url' => 'https://feeds.bbci.co.uk/news/politics/rss.xml',        'source' => 'BBC News', 'category' => 'politics'],
        ['url' => 'https://feeds.bbci.co.uk/news/science_and_environment/rss.xml', 'source' => 'BBC News', 'category' => 'science'],
    ];

    // ── HQ Unsplash fallback images per category ───────────────────────���───
    private array $fallbackImages = [
        'world'         => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?w=1200&q=80',
        'uk'            => 'https://images.unsplash.com/photo-1513635269975-59663e0ac1ad?w=1200&q=80',
        'politics'      => 'https://images.unsplash.com/photo-1529107386315-e1a2ed48a620?w=1200&q=80',
        'business'      => 'https://images.unsplash.com/photo-1611974789855-9c2a0a7236a3?w=1200&q=80',
        'technology'    => 'https://images.unsplash.com/photo-1518770660439-4636190af475?w=1200&q=80',
        'sport'         => 'https://images.unsplash.com/photo-1461896836934-ffe607ba8211?w=1200&q=80',
        'entertainment' => 'https://images.unsplash.com/photo-1603739903239-8b6e64c3b185?w=1200&q=80',
        'science'       => 'https://images.unsplash.com/photo-1507413245164-6160d8298b31?w=1200&q=80',
    ];

    public function handle(): int
    {
        $dryRun   = $this->option('dry-run');
        $limit    = (int) $this->option('limit');

        $channelId  = DB::table('channels')->where('slug', 'cni-news')->value('id');
        $languageId = DB::table('languages')->where('code', 'en')->value('id');
        $botUserId  = DB::table('users')->where('email', 'rss-bot@cninews.tv')->value('id');

        if (!$channelId || !$languageId || !$botUserId) {
            $this->error('Setup incomplete. Run: php artisan db:seed --class=RssImportSeeder');
            return self::FAILURE;
        }

        // Track per-category counts so we stop at $limit each
        $categoryCount = [];
        $imported = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($this->feeds as $feed) {
            $categorySlug = $feed['category'];

            $categoryId = DB::table('categories')
                ->where('channel_id', $channelId)
                ->where('slug', $categorySlug)
                ->value('id');

            if (!$categoryId) {
                $this->warn("Category '{$categorySlug}' not found.");
                continue;
            }

            // Already hit the per-category limit from a previous feed for this category
            if (($categoryCount[$categorySlug] ?? 0) >= $limit) {
                continue;
            }

            $this->line("<fg=cyan>Fetching [{$categorySlug}]:</> {$feed['url']}");

            try {
                $xml = $this->fetchFeed($feed['url']);
            } catch (\Throwable $e) {
                $this->warn("  Failed to fetch: " . $e->getMessage());
                $failed++;
                continue;
            }

            foreach ($xml->channel->item as $item) {
                if (($categoryCount[$categorySlug] ?? 0) >= $limit) {
                    break;
                }

                $title   = trim((string) $item->title);
                $summary = trim(strip_tags((string) $item->description));
                $link    = trim((string) $item->link);
                $pubDate = trim((string) $item->pubDate);

                if (!$title) continue;

                $baseSlug = Str::slug(mb_substr($title, 0, 200));

                if (DB::table('articles')
                    ->where('channel_id', $channelId)
                    ->where('slug', 'like', $baseSlug . '%')
                    ->exists()
                ) {
                    $skipped++;
                    continue;
                }

                // Get image: RSS first, then HQ category fallback
                $imageUrl = $this->extractImageUrl($item) ?? ($this->fallbackImages[$categorySlug] ?? null);

                if ($dryRun) {
                    $this->line("  <fg=yellow>[DRY RUN]</> {$title}" . ($imageUrl ? ' [img]' : ''));
                    $categoryCount[$categorySlug] = ($categoryCount[$categorySlug] ?? 0) + 1;
                    $imported++;
                    continue;
                }

                $slug        = $this->uniqueSlug($baseSlug, $channelId);
                $publishedAt = $pubDate ? date('Y-m-d H:i:s', strtotime($pubDate)) : now()->toDateTimeString();
                $body        = '<p>' . e($summary) . '</p>'
                             . '<p><strong>Source:</strong> <a href="' . e($link) . '" target="_blank" rel="noopener noreferrer">'
                             . 'Read full article at ' . e($feed['source']) . '</a></p>';

                DB::beginTransaction();
                try {
                    $mediaId = null;
                    if ($imageUrl) {
                        $mediaId = DB::table('media_assets')->insertGetId([
                            'owner_user_id'    => $botUserId,
                            'channel_id'       => $channelId,
                            'type'             => 'image',
                            'storage_provider' => 's3',
                            'disk'             => 's3',
                            'original_url'     => $imageUrl,
                            'alt_text'         => mb_substr($title, 0, 320),
                            'uploaded_at'      => now(),
                            'created_at'       => now(),
                            'updated_at'       => now(),
                        ]);
                    }

                    $articleId = DB::table('articles')->insertGetId([
                        'channel_id'              => $channelId,
                        'primary_language_id'     => $languageId,
                        'slug'                    => $slug,
                        'status'                  => 'published',
                        'type'                    => 'news',
                        'author_user_id'          => $botUserId,
                        'main_category_id'        => $categoryId,
                        'featured_image_media_id' => $mediaId,
                        'is_breaking'             => false,
                        'is_featured'             => false,
                        'allow_comments'          => false,
                        'published_at'            => $publishedAt,
                        'created_at'              => now(),
                        'updated_at'              => now(),
                    ]);

                    DB::table('article_translations')->insert([
                        'article_id'  => $articleId,
                        'language_id' => $languageId,
                        'title'       => mb_substr($title, 0, 320),
                        'summary'     => mb_substr($summary, 0, 500) ?: null,
                        'body'        => $body,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);

                    DB::commit();
                    $categoryCount[$categorySlug] = ($categoryCount[$categorySlug] ?? 0) + 1;
                    $imported++;
                    $this->line("  <fg=green>+</> [{$categorySlug}] {$title}");
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $this->warn("  Failed: {$title} — " . $e->getMessage());
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info("Imported: {$imported}  |  Skipped: {$skipped}  |  Failed: {$failed}");
        return self::SUCCESS;
    }

    private function extractImageUrl(\SimpleXMLElement $item): ?string
    {
        $media = $item->children('http://search.yahoo.com/mrss/');

        if (!empty($media->thumbnail)) {
            $url = (string) $media->thumbnail->attributes()->url;
            if ($url) return $url;
        }

        if (!empty($media->content)) {
            foreach ($media->content as $content) {
                $attrs  = $content->attributes();
                $medium = (string) $attrs->medium;
                $url    = (string) $attrs->url;
                if ($url && ($medium === 'image' || preg_match('/\.(jpe?g|png|webp)/i', $url))) {
                    return $url;
                }
            }
        }

        if (!empty($item->enclosure)) {
            $attrs = $item->enclosure->attributes();
            $type  = (string) $attrs->type;
            $url   = (string) $attrs->url;
            if ($url && str_starts_with($type, 'image/')) return $url;
        }

        return null;
    }

    private function fetchFeed(string $url): \SimpleXMLElement
    {
        $ctx = stream_context_create(['http' => [
            'timeout'    => 15,
            'user_agent' => 'CNI News RSS Importer/1.0 (+https://cninews.tv)',
        ]]);

        $content = @file_get_contents($url, false, $ctx);
        if ($content === false) throw new \RuntimeException("Cannot fetch: {$url}");

        $xml = @simplexml_load_string($content);
        if ($xml === false) throw new \RuntimeException("Invalid XML: {$url}");

        return $xml;
    }

    private function uniqueSlug(string $base, int $channelId): string
    {
        $slug = $base;
        $i    = 1;
        while (DB::table('articles')->where('channel_id', $channelId)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
