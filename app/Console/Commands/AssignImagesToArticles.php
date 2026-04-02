<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use App\Models\Article;
use App\Models\MediaAsset;
use App\Models\MediaAssetVariant;

class AssignImagesToArticles extends Command
{
    protected $signature   = 'articles:assign-images {--force : Re-assign images even if article already has one}';
    protected $description = 'Fetch images from Pexels and attach them to articles missing a featured image.';

    // Pexels API key — move to .env as PEXELS_API_KEY in production
    protected string $pexelsApiKey = '3Qz7jckii39trimGlJhBid3ChMNFp8oSqSbdtSZInG7eUowCia9SZ9vl';

    public function handle(): void
    {
        $query = Article::with(['translations', 'mainCategory']);

        if (! $this->option('force')) {
            $query->whereNull('featured_image_media_id');
        }

        $articles = $query->get();
        $this->info("Found {$articles->count()} articles to process.");

        $bar = $this->output->createProgressBar($articles->count());
        $bar->start();

        foreach ($articles as $article) {
            $searchTerm = $this->buildSearchQuery($article);
            $imageUrl   = $this->searchPexels($searchTerm);

            if (! $imageUrl) {
                // Fallback: use category name alone
                $imageUrl = $this->searchPexels($article->mainCategory?->default_name ?? 'news');
            }

            if (! $imageUrl) {
                $this->newLine();
                $this->warn("  ⚠ No image found for: {$article->slug}");
                $bar->advance();
                continue;
            }

            try {
                $media = $this->createMediaAsset($imageUrl, $article);
                $article->update(['featured_image_media_id' => $media->id]);
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("  ✗ Failed for {$article->slug}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Done! Run: php artisan storage:link  (if not already done)');
    }

    // ── Build a good search query from article title + category ──────────
    protected function buildSearchQuery(Article $article): string
    {
        $title    = $article->translations->first()?->title ?? '';
        $category = $article->mainCategory?->default_name ?? '';

        // Use first 3-4 meaningful words of title + category
        $words = collect(explode(' ', strip_tags($title)))
            ->filter(fn($w) => strlen($w) > 3)
            ->take(4)
            ->implode(' ');

        return trim("{$words} {$category}") ?: 'pakistan news';
    }

    // ── Search Pexels for a relevant landscape photo ─────────────────────
    protected function searchPexels(string $query): ?string
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => $this->pexelsApiKey,
            ])->timeout(10)->get('https://api.pexels.com/v1/search', [
                'query'       => $query,
                'per_page'    => 5,
                'orientation' => 'landscape',
            ]);

            if ($response->successful()) {
                $photos = $response->json('photos', []);
                if (! empty($photos)) {
                    // Pick a random one from the top 5 for variety
                    $photo = $photos[array_rand($photos)];
                    // Use 'large2x' for high quality, or 'large' as fallback
                    return $photo['src']['large2x'] ?? $photo['src']['large'] ?? $photo['src']['original'];
                }
            }
        } catch (\Exception $e) {
            $this->warn("  Pexels error: {$e->getMessage()}");
        }

        return null;
    }

    // ── Download image and create media_asset + variants ─────────────────
    protected function createMediaAsset(string $url, Article $article): MediaAsset
    {
        $slug     = Str::slug($article->slug ?? 'article');
        $filename = "articles/{$slug}-" . time() . '.jpg';

        // Download the image
        $imageContents = Http::timeout(30)->get($url)->body();

        // Store on the 'public' disk (web-accessible via /storage)
        Storage::disk('public')->put($filename, $imageContents);

        // Create the media_asset row
        // We store original_url = Pexels URL (direct CDN link, always works)
        // AND internal_path = local copy (works offline / after Pexels link expires)
        $media = MediaAsset::create([
            'owner_user_id'    => null,
            'channel_id'       => \DB::table('channels')->where('slug', 'cni-news')->value('id'),
            'type'             => 'image',
            'storage_provider' => 'local',
            'disk'             => 'public',
            'original_url'     => $url,                       // ← Pexels CDN URL (fallback)
            'internal_path'    => $filename,                  // ← local stored copy
            'title'            => $article->translations->first()?->title,
            'alt_text'         => $article->translations->first()?->title,
            'mime_type'        => 'image/jpeg',
        ]);

        return $media;
    }
}
