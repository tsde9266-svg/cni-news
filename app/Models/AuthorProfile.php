<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuthorProfile extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'user_id','pen_name','byline','bio','bio_short','profile_photo_media_id',
        'twitter_url','facebook_url','instagram_url','linkedin_url','website_url','youtube_url',
        'can_self_publish','is_monetised','default_rate_type','default_rate_amount',
        'rate_currency','payout_method','payout_details_encrypted','is_active',
    ];
    protected $casts = ['can_self_publish'=>'boolean','is_monetised'=>'boolean','is_active'=>'boolean'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function articles(): HasMany { return $this->hasMany(Article::class, 'author_user_id', 'user_id'); }
    public function earnings(): HasMany { return $this->hasMany(AuthorEarning::class); }
    public function payouts(): HasMany { return $this->hasMany(AuthorPayout::class); }
}
