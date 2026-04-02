<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoItem extends Model {
    use SoftDeletes;
    protected $fillable = ['channel_id','media_asset_id','platform','external_url','embed_code',
        'is_live_recording','status','view_count','published_at'];
    protected $casts = ['is_live_recording'=>'boolean','published_at'=>'datetime'];
    public function channel(): BelongsTo { return $this->belongsTo(Channel::class); }
    public function translations(): HasMany { return $this->hasMany(VideoTranslation::class); }
    public function mediaAsset(): BelongsTo { return $this->belongsTo(MediaAsset::class); }
    public function scopePublished($q) { return $q->where('status','published'); }
}
