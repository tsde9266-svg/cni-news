<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Flush cached article view counts from Redis/file cache into MySQL.
 * Schedule every 5 minutes in routes/console.php:
 *   Schedule::command('cni:flush-view-counts')->everyFiveMinutes();
 */
class FlushViewCountsCommand extends Command
{
    protected $signature   = 'cni:flush-view-counts';
    protected $description = 'Flush cached article view counts into the database';

    public function handle(): int
    {
        $prefix  = 'article_views:';
        $flushed = 0;

        try {
            // Get all article IDs that have cached view counts
            $store = Cache::getStore();

            // File cache: scan cache directory for matching keys
            // Redis cache: use KEYS pattern
            $articleIds = DB::table('articles')->pluck('id');

            foreach ($articleIds as $articleId) {
                $cacheKey = "{$prefix}{$articleId}";

                try {
                    $views = Cache::get($cacheKey, 0);

                    if ($views > 0) {
                        DB::table('articles')
                            ->where('id', $articleId)
                            ->increment('view_count', (int) $views);

                        Cache::forget($cacheKey);
                        $flushed++;
                    }
                } catch (\Exception $e) {
                    // Skip individual failures
                    continue;
                }
            }
        } catch (\Exception $e) {
            Log::error('FlushViewCounts failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        if ($flushed > 0) {
            $this->info("Flushed view counts for {$flushed} articles.");
        }

        return self::SUCCESS;
    }
}
