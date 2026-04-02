<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleTranslation extends Model
{
    protected $fillable = [
        'article_id','language_id','title','subtitle','summary',
        'body','seo_title','seo_description','seo_slug_override',
    ];

    public function article(): BelongsTo { return $this->belongsTo(Article::class); }
    public function language(): BelongsTo { return $this->belongsTo(Language::class); }
}
