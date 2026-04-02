<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'owner_user_id','channel_id','type','storage_provider','disk',
        'original_url','internal_path','external_id','title','description',
        'alt_text','mime_type','size_bytes','width','height','duration_seconds',
    ];
    public function variants(): HasMany { return $this->hasMany(MediaAssetVariant::class); }
}
