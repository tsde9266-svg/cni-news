<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    use SoftDeletes;
    protected $fillable = ['channel_id','parent_id','slug','default_name','default_description','position','is_featured','is_active'];
    protected $casts = ['is_featured' => 'boolean','is_active' => 'boolean'];

    public function parent(): BelongsTo { return $this->belongsTo(Category::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(Category::class, 'parent_id'); }
    public function translations(): HasMany { return $this->hasMany(CategoryTranslation::class); }
    public function articles(): HasMany { return $this->hasMany(Article::class, 'main_category_id'); }
}
