<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipPlan extends Model
{
    protected $fillable = [
        'channel_id', 'name', 'slug', 'description',
        'price_amount', 'price_currency', 'billing_cycle',
        'stripe_price_id', 'paypal_plan_id',
        'max_devices', 'features', 'sort_order',
        'badge_color', 'badge_label',
        'is_active', 'is_publicly_visible', 'is_free_tier',
    ];

    protected $casts = [
        'features'           => 'array',
        'price_amount'       => 'decimal:2',
        'is_active'          => 'boolean',
        'is_publicly_visible'=> 'boolean',
        'is_free_tier'       => 'boolean',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class, 'applicable_plan_id');
    }

    // ── Feature helpers ────────────────────────────────────────────────────

    public function hasFeature(string $key): bool
    {
        return ($this->features[$key] ?? false) === true;
    }

    public function isFreePlan(): bool
    {
        return $this->is_free_tier || $this->price_amount == 0;
    }

    public function formattedPrice(): string
    {
        if ($this->isFreePlan()) return 'Free';
        return '£' . number_format($this->price_amount, 2)
               . ' / ' . $this->billing_cycle;
    }
}
