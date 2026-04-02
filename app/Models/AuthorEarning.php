<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AuthorEarning extends Model
{
    protected $fillable = [
        'author_profile_id','article_id','earning_type','amount','currency',
        'description','units','rate_applied','status','approved_by_user_id','payout_id',
    ];
    protected $casts = ['amount'=>'decimal:4','rate_applied'=>'decimal:4','earned_at'=>'datetime'];
    public function authorProfile() { return $this->belongsTo(AuthorProfile::class); }
    public function article() { return $this->belongsTo(Article::class); }
}
