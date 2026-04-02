<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AuthorPayout extends Model
{
    protected $fillable = [
        'author_profile_id','processed_by_user_id','amount','currency',
        'method','status','reference','notes','period_from','period_to','paid_at',
    ];
    protected $casts = ['paid_at'=>'datetime'];
    public function authorProfile() { return $this->belongsTo(AuthorProfile::class); }
}
