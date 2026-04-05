<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisplayAd extends Model
{
    protected $fillable = [
        'title', 'image_url', 'click_url', 'alt_text',
        'placement', 'is_active', 'display_order',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
