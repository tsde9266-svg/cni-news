<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    protected $fillable = [
        'channel_id', 'created_by_user_id', 'code', 'description',
        'discount_type', 'discount_value', 'currency',
        'applicable_plan_id', 'max_uses', 'uses_count',
        'max_uses_per_user', 'valid_from', 'valid_until',
        'is_active', 'stripe_coupon_id',
    ];

    protected $casts = [
        'discount_value'    => 'decimal:2',
        'is_active'         => 'boolean',
        'valid_from'        => 'date',
        'valid_until'       => 'date',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function applicablePlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'applicable_plan_id');
    }

    public function uses(): HasMany
    {
        return $this->hasMany(PromoCodeUse::class);
    }

    // ── Validation helpers ─────────────────────────────────────────────────

    public function isValid(?int $userId = null): bool
    {
        if (! $this->is_active) return false;

        $now = now()->toDateString();

        if ($this->valid_from && $this->valid_from->gt(now())) return false;
        if ($this->valid_until && $this->valid_until->lt(now())) return false;
        if ($this->max_uses && $this->uses_count >= $this->max_uses) return false;

        if ($userId && $this->max_uses_per_user) {
            $userUses = $this->uses()->where('user_id', $userId)->count();
            if ($userUses >= $this->max_uses_per_user) return false;
        }

        return true;
    }

    public function calculateDiscount(float $originalPrice): float
    {
        if ($this->discount_type === 'percentage') {
            return round($originalPrice * ($this->discount_value / 100), 2);
        }
        return min((float) $this->discount_value, $originalPrice);
    }
}
