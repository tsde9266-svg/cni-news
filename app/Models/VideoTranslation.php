<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class VideoTranslation extends Model {
    protected $fillable = ['video_item_id','language_id','title','description','seo_title','seo_description'];
    public function video() { return $this->belongsTo(VideoItem::class,'video_item_id'); }
    public function language() { return $this->belongsTo(Language::class); }
}
