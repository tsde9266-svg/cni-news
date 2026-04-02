<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveStream extends Model {
    protected $fillable = ['channel_id','primary_platform','platform_stream_id','title','description',
        'thumbnail_media_id','scheduled_start_at','actual_start_at','actual_end_at',
        'status','is_public','peak_viewers','recorded_video_id'];
    protected $casts = ['is_public'=>'boolean','scheduled_start_at'=>'datetime',
        'actual_start_at'=>'datetime','actual_end_at'=>'datetime'];
    public function channel(): BelongsTo { return $this->belongsTo(Channel::class); }
    public function platformLinks(): HasMany { return $this->hasMany(LiveStreamPlatformLink::class); }
    public function recordedVideo(): BelongsTo { return $this->belongsTo(VideoItem::class,'recorded_video_id'); }
}
