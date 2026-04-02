<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdBooking extends Model
{
    protected $fillable = [
        'reference', 'ad_package_id',
        'advertiser_name', 'advertiser_email', 'advertiser_phone',
        'company_name', 'company_website',
        'campaign_title', 'creative_url', 'click_url', 'brief_text',
        'start_date', 'end_date',
        'price_amount', 'price_currency',
        'payment_status', 'booking_status',
        'stripe_checkout_session_id', 'stripe_payment_intent_id',
        'paid_at', 'receipt_url',
        'reviewed_by_user_id', 'reviewed_at', 'rejection_reason', 'admin_notes',
        'ip_address',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'paid_at'     => 'datetime',
        'reviewed_at' => 'datetime',
        'price_amount'=> 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $booking) {
            if (empty($booking->reference)) {
                $booking->reference = static::generateReference();
            }
        });
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(AdPackage::class, 'ad_package_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isAwaitingReview(): bool
    {
        return $this->booking_status === 'pending_review';
    }

    private static function generateReference(): string
    {
        $last = static::latest('id')->value('reference');
        $seq  = $last ? ((int) substr($last, -6)) + 1 : 1;
        return 'CNI-AD-' . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
