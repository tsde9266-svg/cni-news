<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AdCampaign extends Model {
    protected $fillable = ['advertiser_id','channel_id','name','description',
        'start_date','end_date','budget_amount','budget_currency','status','invoice_reference'];
    protected $casts = ['start_date'=>'date','end_date'=>'date','budget_amount'=>'decimal:2'];
    public function advertiser() { return $this->belongsTo(Advertiser::class); }
    public function creatives() { return $this->hasMany(AdCreative::class); }
}
