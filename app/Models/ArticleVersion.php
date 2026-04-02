<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleVersion extends Model
{
    public $timestamps = false;
    protected $fillable = ['article_id','language_id','version_number','title','body','saved_by_user_id','change_summary'];
    protected $casts = ['created_at' => 'datetime'];

    public function article(): BelongsTo { return $this->belongsTo(Article::class); }
    public function savedBy(): BelongsTo { return $this->belongsTo(User::class, 'saved_by_user_id'); }
    public function language(): BelongsTo { return $this->belongsTo(Language::class); }
}
