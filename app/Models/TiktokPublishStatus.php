<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TiktokPublishStatus extends Model
{
    protected $fillable = [
        'social_post_id',
        'publish_id',
        'tiktok_status',
        'fail_reason',
        'tiktok_post_id',
        'poll_count',
        'next_poll_at',
        'abandon_after',
    ];

    protected $casts = [
        'next_poll_at'  => 'datetime',
        'abandon_after' => 'datetime',
    ];

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    /** True if polling should be abandoned (timeout reached) */
    public function shouldAbandon(): bool
    {
        return $this->abandon_after && $this->abandon_after->isPast();
    }

    /** True if we should poll right now */
    public function isDue(): bool
    {
        if ($this->shouldAbandon()) return false;
        if (!$this->next_poll_at) return true;
        return $this->next_poll_at->isPast();
    }

    /** Calculate next poll time using exponential backoff (30s, 1m, 2m, 4m, 8m...) */
    public function scheduleNextPoll(): void
    {
        $seconds = min(30 * pow(2, $this->poll_count), 300); // cap at 5 minutes
        $this->update([
            'poll_count'   => $this->poll_count + 1,
            'next_poll_at' => now()->addSeconds($seconds),
        ]);
    }
}
