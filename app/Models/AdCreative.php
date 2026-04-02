<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AdCreative extends Model {
    protected $fillable = ['ad_campaign_id','media_asset_id','type','click_url','alt_text',
        'impression_goal','click_goal','status'];
    public function campaign() { return $this->belongsTo(AdCampaign::class,'ad_campaign_id'); }
    public function mediaAsset() { return $this->belongsTo(MediaAsset::class); }
    public function servingRules() { return $this->hasMany(AdServingRule::class); }
}
