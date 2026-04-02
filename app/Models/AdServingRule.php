<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AdServingRule extends Model {
    protected $fillable = ['ad_creative_id','ad_placement_id','device_type',
        'geo_targeting_json','language_targeting_json','start_date','end_date','weight'];
    protected $casts = ['geo_targeting_json'=>'array','language_targeting_json'=>'array',
        'start_date'=>'date','end_date'=>'date'];
}
