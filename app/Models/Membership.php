<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    protected $fillable = [
        'user_id','membership_plan_id','promo_code_id',
        'stripe_subscription_id','stripe_customer_id','paypal_subscription_id',
        'status','trial_ends_at','start_date','end_date','auto_renew','canceled_at','cancel_reason',
    ];
    protected $casts = ['trial_ends_at'=>'datetime','canceled_at'=>'datetime','auto_renew'=>'boolean'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function plan(): BelongsTo { return $this->belongsTo(MembershipPlan::class, 'membership_plan_id'); }
    public function promoCode(): BelongsTo { return $this->belongsTo(PromoCode::class); }
}
