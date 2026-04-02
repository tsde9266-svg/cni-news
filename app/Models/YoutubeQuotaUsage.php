<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YoutubeQuotaUsage extends Model
{
    protected $fillable = [
        'social_account_id',
        'quota_date',
        'units_used',
        'uploads_count',
        'reads_count',
    ];

    protected $casts = [
        'quota_date' => 'date',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Add units to today's quota usage.
     * Creates the row if it doesn't exist yet.
     */
    public static function consume(int $socialAccountId, int $units, string $type = 'read'): void
    {
        $today = now()->timezone('America/Los_Angeles')->toDateString();

        $row = self::firstOrCreate(
            ['social_account_id' => $socialAccountId, 'quota_date' => $today],
            ['units_used' => 0, 'uploads_count' => 0, 'reads_count' => 0]
        );

        $row->increment('units_used', $units);

        if ($type === 'upload') {
            $row->increment('uploads_count');
        } else {
            $row->increment('reads_count');
        }
    }

    /**
     * How many quota units are available today for a given account.
     * Default daily limit is 10,000 units.
     */
    public static function availableToday(int $socialAccountId, int $dailyLimit = 10000): int
    {
        $today = now()->timezone('America/Los_Angeles')->toDateString();
        $used = self::where('social_account_id', $socialAccountId)
            ->where('quota_date', $today)
            ->value('units_used') ?? 0;

        return max(0, $dailyLimit - $used);
    }
}
