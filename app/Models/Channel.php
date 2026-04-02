<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    protected $fillable = ['name','slug','description','primary_language_id','is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function articles(): HasMany { return $this->hasMany(Article::class); }
    public function categories(): HasMany { return $this->hasMany(Category::class); }
}
