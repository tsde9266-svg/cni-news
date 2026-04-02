<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model {
    use SoftDeletes;
    protected $fillable = ['channel_id','event_type_id','organizer_user_id','title','description',
        'location_name','address','city','country','latitude','longitude',
        'starts_at','ends_at','is_public','status','cover_media_id','ticket_price','max_capacity'];
    protected $casts = ['is_public'=>'boolean','starts_at'=>'datetime','ends_at'=>'datetime','ticket_price'=>'decimal:2'];
    public function channel(): BelongsTo { return $this->belongsTo(Channel::class); }
    public function eventType(): BelongsTo { return $this->belongsTo(EventType::class); }
    public function organizer(): BelongsTo { return $this->belongsTo(User::class,'organizer_user_id'); }
    public function translations(): HasMany { return $this->hasMany(EventTranslation::class); }
    public function registrations(): HasMany { return $this->hasMany(EventRegistration::class); }
    public function coverMedia(): BelongsTo { return $this->belongsTo(MediaAsset::class,'cover_media_id'); }
    public function scopePublished($q) { return $q->where('status','published'); }
    public function scopeUpcoming($q) { return $q->where('starts_at','>',now()); }
}
