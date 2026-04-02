<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialFeedItem extends Model
{
    protected $fillable = [
        'channel_id',
        'social_account_id',
        'platform',
        'platform_item_id',
        'content_type',
        'caption',
        'media_url',
        'thumbnail_url',
        'permalink',
        'title',
        'duration_seconds',
        'views_count',
        'likes_count',
        'comments_count',
        'shares_count',
        'posted_at',
        'is_visible',
        'is_pinned',
        'display_order',
        'raw_data',
    ];

    protected $casts = [
        'posted_at'   => 'datetime',
        'is_visible'  => 'boolean',
        'is_pinned'   => 'boolean',
        'raw_data'    => 'array',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopeLatest($query)
    {
        return $query->orderByDesc('is_pinned')
                     ->orderBy('display_order')
                     ->orderByDesc('posted_at');
    }
}
