<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ArticleService
{
    /**
     * Increment view count via Redis to avoid a DB write on every page view.
     * A scheduled command (php artisan cni:flush-view-counts) runs every 5 min
     * and writes cached counts back to MySQL.
     */
    public function incrementViewCount(int $articleId): void
    {
        try {
            Cache::increment("article_views:{$articleId}");
        } catch (\Exception) {
            // Fallback: direct DB increment if Redis not available
            DB::table('articles')->where('id', $articleId)->increment('view_count');
        }
    }

    /**
     * Count words in article body (strips HTML first).
     */
    public function countWords(Article $article): int
    {
        $translation = $article->translations->first();
        if (! $translation) return 0;

        return str_word_count(strip_tags($translation->body ?? ''));
    }

    /**
     * Record an author earning when an article is published.
     * Rate priority: per-article override → author default rate → skip.
     */
    public function recordAuthorEarning(Article $article): void
    {
        $profile = DB::table('author_profiles')
            ->where('user_id', $article->author_user_id)
            ->first();

        if (! $profile || ! $profile->is_monetised) return;

        $override = DB::table('author_article_rates')
            ->where('article_id', $article->id)
            ->first();

        $rateType   = $override?->rate_type   ?? $profile->default_rate_type;
        $rateAmount = $override?->rate_amount  ?? $profile->default_rate_amount;
        $currency   = $override?->currency     ?? $profile->rate_currency;

        if (! $rateType || ! $rateAmount) return;

        switch ($rateType) {
            case 'per_article':
                $this->insertEarning($profile->id, $article->id, 'per_article',
                    $rateAmount, $currency,
                    "Flat fee — {$article->slug}",
                    null, $rateAmount);
                break;

            case 'per_word':
                $wordCount = $article->word_count ?? $this->countWords($article);
                $amount    = round($wordCount * $rateAmount, 4);
                $this->insertEarning($profile->id, $article->id, 'per_word',
                    $amount, $currency,
                    "{$wordCount} words × £{$rateAmount}",
                    $wordCount, $rateAmount);
                break;

            case 'per_view':
                // Placeholder — settled monthly by scheduler
                $this->insertEarning($profile->id, $article->id, 'per_view',
                    0, $currency,
                    'Per-view — settled monthly',
                    0, $rateAmount);
                break;
        }
    }

    private function insertEarning(
        int    $authorProfileId,
        int    $articleId,
        string $type,
        float  $amount,
        string $currency,
        string $description,
        ?int   $units,
        float  $rateApplied
    ): void {
        DB::table('author_earnings')->insert([
            'author_profile_id' => $authorProfileId,
            'article_id'        => $articleId,
            'earning_type'      => $type,
            'amount'            => $amount,
            'currency'          => $currency,
            'description'       => $description,
            'units'             => $units,
            'rate_applied'      => $rateApplied,
            'status'            => 'pending',
            'earned_at'         => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
}
