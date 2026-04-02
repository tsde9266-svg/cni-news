<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PromoCodeUse extends Model
{
    public $timestamps = false;
    protected $fillable = ['promo_code_id','user_id','payment_id'];
    protected $casts = ['used_at'=>'datetime'];
}
