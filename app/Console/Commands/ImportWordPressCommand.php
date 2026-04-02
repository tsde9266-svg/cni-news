<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Import articles from a WordPress site using the WP REST API.
 *
 * Usage:
 *   php artisan cni:import-wordpress --url=https://old-site.com --limit=500
 *   php artisan cni:import-wordpress --url=https://old-site.com --page=2 --limit=100
 *   php artisan cni:import-wordpress --url=https://old-site.com --dry-run
 */
class ImportWordPressCommand extends Command
{
    protected $signature = 'cni:import-wordpress
        {--url=         : WordPress site base URL (e.g. https://old-site.com)}
        {--limit=100    : Max articles to import per run}
        {--page=1       : WP REST API page to start from}
        {--dry-run      : Preview what would be imported without writing to DB}
        {--channel=cni-news : Channel slug to assign articles to}
        {--lang=en      : Language code to assign translations to}
        {--category=    : Default category slug if WP category cannot be matched}';

    protected $description = 'Import articles from a WordPress site via its REST API';

    public function handle(): int
    {
        $wpUrl    = rtrim($this->option('url'), '/');
        $limit    = (int) $this->option('limit');
        $page     = (int) $this->option('page');
        $dryRun   = $this->option('dry-run');
        $langCode = $this->option('lang');
        $chanSlug = $this->option('channel');

        if (!$wpUrl) {
            $this->error('--url is required. Example: php artisan cni:import-wordpress --url=https://old-site.com');
            return self::FAILURE;
        }

        // Resolve IDs
        $channelId = DB::table('channels')->where('slug', $chanSlug)->value('id');
        $langId    = DB::table('languages')->where('code', $langCode)->value('id');

        if (!$channelId) { $this->error("Channel '{$chanSlug}' not found."); return self::FAILURE; }
        if (!$langId)    { $this->error("Language '{$langCode}' not found."); return self::FAILURE; }

        $defaultCatId = DB::table('categories')
            ->where('slug', $this->option('category') ?? 'general')
            ->value('id');

        $this->info("WordPress Importer — {$wpUrl}");
        $this->info("Channel: {$chanSlug} ({$channelId}) | Language: {$langCode} ({$langId}) | Limit: {$limit}");
        $dryRun && $this->warn('DRY RUN — no data will be written.');

        $apiBase   = "{$wpUrl}/wp-json/wp/v2";
        $imported  = 0;
        $skipped   = 0;
        $errors    = 0;
        $perPage   = min($limit, 50);

        while ($imported < $limit) {
            $this->line("Fetching page {$page}…");

            try {
                $response = Http::timeout(30)->get("{$apiBase}/posts", [
                    'page'     => $page,
                    'per_page' => $perPage,
                    '_embed'   => true,
                    'status'   => 'publish',
                ]);
            } catch (\Exception $e) {
                $this->error("HTTP error: {$e->getMessage()}");
                break;
            }

            if ($response->status() === 400 || !$response->json()) {
                $this->info('No more pages.');
                break;
            }

            $posts = $response->json();
            if (empty($posts)) break;

            $bar = $this->output->createProgressBar(count($posts));
            $bar->start();

            foreach ($posts as $post) {
                try {
                    $result = $this->importPost($post, $channelId, $langId, $defaultCatId, $wpUrl, $dryRun);
                    $result === 'imported' ? $imported++ : $skipped++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->newLine();
                    $this->warn("Error on post {$post['id']}: {$e->getMessage()}");
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            if (count($posts) < $perPage || $imported >= $limit) break;
            $page++;
        }

        $this->newLine();
        $this->table(
            ['Imported', 'Skipped (already exists)', 'Errors'],
            [[$imported, $skipped, $errors]]
        );

        return self::SUCCESS;
    }

    private function importPost(
        array  $post,
        int    $channelId,
        int    $langId,
        ?int   $defaultCatId,
        string $wpUrl,
        bool   $dryRun
    ): string {
        $slug = $post['slug'];

        // Skip if already imported
        if (DB::table('articles')->where('slug', $slug)->exists()) {
            return 'skipped';
        }

        $title   = html_entity_decode(strip_tags($post['title']['rendered'] ?? ''));
        $body    = $post['content']['rendered'] ?? '';
        $summary = html_entity_decode(strip_tags($post['excerpt']['rendered'] ?? ''));
        $pubAt   = $post['date'] ?? null;

        // Map WP category to CNI category
        $catId = $defaultCatId;
        $wpCats = $post['_embedded']['wp:term'][0] ?? [];
        foreach ($wpCats as $wpCat) {
            $cniCat = DB::table('categories')
                ->where('slug', Str::slug($wpCat['slug']))
                ->value('id');
            if ($cniCat) { $catId = $cniCat; break; }
        }

        // Find or create author
        $wpAuthor    = $post['_embedded']['author'][0] ?? null;
        $authorEmail = $wpAuthor['slug'] ? "{$wpAuthor['slug']}@wp-import.local" : 'wp-import@cni.co.uk';
        $authorId    = DB::table('users')->where('email', $authorEmail)->value('id');

        if (!$authorId && !$dryRun) {
            $authorId = DB::table('users')->insertGetId([
                'channel_id'    => $channelId,
                'email'         => $authorEmail,
                'first_name'    => $wpAuthor['name'] ?? 'WordPress',
                'last_name'     => 'Import',
                'display_name'  => $wpAuthor['name'] ?? 'WordPress Import',
                'password_hash' => '',
                'status'        => 'active',
                'timezone'      => 'Europe/London',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // Log the redirect from old WP URL
        $oldPath = parse_url($post['link'], PHP_URL_PATH);

        if ($dryRun) {
            $this->line("  [DRY] Would import: {$slug} | {$title}");
            return 'imported';
        }

        DB::transaction(function () use (
            $slug, $title, $body, $summary, $pubAt, $channelId,
            $langId, $catId, $authorId, $oldPath
        ) {
            $articleId = DB::table('articles')->insertGetId([
                'channel_id'          => $channelId,
                'primary_language_id' => $langId,
                'slug'                => $slug,
                'status'              => 'published',
                'type'                => 'news',
                'author_user_id'      => $authorId ?? 1,
                'main_category_id'    => $catId,
                'published_at'        => $pubAt ? date('Y-m-d H:i:s', strtotime($pubAt)) : now(),
                'word_count'          => str_word_count(strip_tags($body)),
                'allow_comments'      => true,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            DB::table('article_translations')->insert([
                'article_id'  => $articleId,
                'language_id' => $langId,
                'title'       => $title,
                'summary'     => $summary,
                'body'        => $body,
                'seo_title'   => $title,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // Create SEO redirect from old WP URL
            if ($oldPath && $oldPath !== "/{$slug}/") {
                DB::table('seo_redirects')->updateOrInsert(
                    ['old_path' => rtrim($oldPath, '/')],
                    [
                        'new_path'   => "/article/{$slug}",
                        'http_code'  => 301,
                        'is_active'  => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        });

        return 'imported';
    }
}
