<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SocialPost extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'channel_id',
        'social_account_id',
        'article_id',
        'created_by_user_id',
        'platform',
        'content_text',
        'link_url',
        'media_asset_id',
        'media_public_url',
        'platform_options',
        'post_type',
        'scheduled_at',
        'status',
        'published_at',
        'platform_post_id',
        'platform_post_url',
        'attempt_count',
        'max_attempts',
        'error_message',
        'error_data',
        'retry_after',
        'queue_job_id',
    ];

    protected $casts = [
        'scheduled_at'   => 'datetime',
        'published_at'   => 'datetime',
        'retry_after'    => 'datetime',
        'platform_options' => 'array',
        'error_data'     => 'array',
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

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function tiktokStatus(): HasOne
    {
        return $this->hasOne(TiktokPublishStatus::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** Get a platform_options value by key */
    public function getOption(string $key, mixed $default = null): mixed
    {
        return ($this->platform_options ?? [])[$key] ?? $default;
    }

    /** True if this post can be retried */
    public function canRetry(): bool
    {
        return $this->status === 'failed'
            && $this->attempt_count < $this->max_attempts;
    }

    /** Calculate the next retry time using exponential backoff */
    public function nextRetryAt(): \Carbon\Carbon
    {
        // 2^attempt minutes: 2min, 4min, 8min
        $minutes = pow(2, $this->attempt_count);
        return now()->addMinutes($minutes);
    }

    /** Mark as publishing (increment attempt counter) */
    public function markPublishing(): void
    {
        $this->update([
            'status'        => 'publishing',
            'attempt_count' => $this->attempt_count + 1,
        ]);
    }

    /** Mark as successfully published */
    public function markPublished(string $platformPostId, ?string $platformPostUrl = null): void
    {
        $this->update([
            'status'           => 'published',
            'published_at'     => now(),
            'platform_post_id' => $platformPostId,
            'platform_post_url'=> $platformPostUrl,
            'error_message'    => null,
            'error_data'       => null,
        ]);
    }

    /** Mark as failed with error details */
    public function markFailed(string $message, array $errorData = []): void
    {
        $updates = [
            'error_message' => $message,
            'error_data'    => $errorData ?: null,
        ];

        if ($this->canRetry()) {
            $updates['status']       = 'failed';
            $updates['retry_after']  = $this->nextRetryAt();
        } else {
            // Exhausted all retries
            $updates['status'] = 'failed';
            $updates['retry_after'] = null;
        }

        $this->update($updates);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeDue($query)
    {
        return $query->where('status', 'queued')
            ->where(fn($q) =>
                $q->whereNull('scheduled_at')
                  ->orWhere('scheduled_at', '<=', now())
            );
    }

    public function scopeRetryable($query)
    {
        return $query->where('status', 'failed')
            ->whereColumn('attempt_count', '<', 'max_attempts')
            ->where('retry_after', '<=', now());
    }
}
