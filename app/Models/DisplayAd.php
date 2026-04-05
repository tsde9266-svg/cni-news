<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DisplayAd extends Model
{
    protected $fillable = [
        'title', 'image_url', 'media_type', 'video_url',
        'click_url', 'alt_text', 'placement',
        'is_active', 'display_order',
        'starts_at', 'ends_at',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: only ads whose campaign window is active right now.
     * Ads with null starts_at/ends_at are always shown.
     */
    public function scopeLive($query)
    {
        $now = Carbon::now();
        return $query->active()->where(function ($q) use ($now) {
            $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
        })->where(function ($q) use ($now) {
            $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
        });
    }
}
