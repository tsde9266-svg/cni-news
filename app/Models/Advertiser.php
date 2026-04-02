<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Advertiser extends Model {
    protected $fillable = ['company_name','contact_name','email','phone','address','user_id','notes','status'];
    public function user() { return $this->belongsTo(User::class); }
    public function campaigns() { return $this->hasMany(AdCampaign::class); }
}
