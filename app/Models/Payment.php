<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'user_id','payable_type','payable_id','membership_id','gateway',
        'gateway_transaction_id','gateway_invoice_id',
        'amount','discount_amount','amount_paid','currency',
        'status','payment_method_type','payment_method_last4','payment_method_brand',
        'receipt_url','refund_amount','refund_reason','paid_at','refunded_at','gateway_metadata',
    ];
    protected $casts = ['paid_at'=>'datetime','refunded_at'=>'datetime','gateway_metadata'=>'array'];
}
