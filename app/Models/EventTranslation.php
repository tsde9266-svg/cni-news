<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EventTranslation extends Model {
    protected $fillable = ['event_id','language_id','title','description','seo_title','seo_description'];
    public function event() { return $this->belongsTo(Event::class); }
    public function language() { return $this->belongsTo(Language::class); }
}
