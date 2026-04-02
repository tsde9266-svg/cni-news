<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdPackage extends Model
{
    protected $fillable = [
        'slug', 'name', 'tagline', 'description',
        'category', 'placement', 'platform',
        'price_amount', 'price_currency', 'duration_days',
        'dimensions', 'max_concurrent', 'features',
        'is_featured', 'is_active', 'sort_order',
        'icon_emoji', 'stripe_price_id',
    ];

    protected $casts = [
        'price_amount'  => 'decimal:2',
        'duration_days' => 'integer',
        'max_concurrent'=> 'integer',
        'sort_order'    => 'integer',
        'is_featured'   => 'boolean',
        'is_active'     => 'boolean',
        'features'      => 'array',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(AdBooking::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Formatted price e.g. "£299" */
    public function getFormattedPriceAttribute(): string
    {
        $symbol = match (strtoupper($this->price_currency)) {
            'GBP' => '£',
            'USD' => '$',
            'EUR' => '€',
            default => $this->price_currency . ' ',
        };
        return $symbol . number_format((float) $this->price_amount, 0);
    }
}
