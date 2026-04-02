<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'article_id','parent_comment_id','user_id','guest_name','guest_email',
        'content','status','spam_score','ip_address','user_agent',
    ];
    protected $casts = ['spam_score' => 'decimal:3'];

    public function article(): BelongsTo { return $this->belongsTo(Article::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function parent(): BelongsTo { return $this->belongsTo(Comment::class, 'parent_comment_id'); }
    public function replies(): HasMany { return $this->hasMany(Comment::class, 'parent_comment_id'); }
}
