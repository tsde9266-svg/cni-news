<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LiveStreamPlatformLink extends Model {
    protected $fillable = ['live_stream_id','platform','platform_stream_id','stream_key','url'];
    public function liveStream() { return $this->belongsTo(LiveStream::class); }
}
