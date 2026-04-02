<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MediaAssetVariant extends Model
{
    protected $fillable = ['media_asset_id','conversion_name','disk','path','mime_type','size_bytes','width','height'];
    public function asset() { return $this->belongsTo(MediaAsset::class); }
}
