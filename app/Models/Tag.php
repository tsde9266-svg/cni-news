<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $fillable = ['channel_id','slug','default_name','description'];
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'article_tag_map');
    }
    public function translations() { return $this->hasMany(TagTranslation::class); }
}
