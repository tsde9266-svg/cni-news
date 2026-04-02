<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EventRegistration extends Model {
    protected $fillable = ['event_id','user_id','guest_name','guest_email','registration_status','tickets_json','payment_id'];
    protected $casts = ['tickets_json'=>'array'];
    public function event() { return $this->belongsTo(Event::class); }
    public function user() { return $this->belongsTo(User::class); }
}
