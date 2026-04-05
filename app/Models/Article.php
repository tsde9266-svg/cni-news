<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;  // ← ADD THIS LINE (Laravel's HasOne)

class Article extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'channel_id', 'primary_language_id', 'slug', 'status', 'type',
        'author_user_id', 'editor_user_id', 'featured_image_media_id',
        'gallery_media_ids',
        'main_category_id', 'is_breaking', 'is_featured', 'allow_comments',
        'published_at', 'scheduled_at',
    ];

    protected $casts = [
        'is_breaking'       => 'boolean',
        'is_featured'       => 'boolean',
        'allow_comments'    => 'boolean',
        'published_at'      => 'datetime',
        'scheduled_at'      => 'datetime',
        'gallery_media_ids' => 'array',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function primaryLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'primary_language_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_user_id');
    }

    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'featured_image_media_id');
    }

    public function mainCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'main_category_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ArticleTranslation::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ArticleVersion::class)->orderByDesc('version_number');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'article_category_map')
                    ->withPivot('is_primary');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'article_tag_map');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    // ── Convenience: get translation for a given locale ────────────────────

    // public function translation(string $langCode = 'en'): ?ArticleTranslation
    // {
    //     return $this->translations
    //         ->where('language.code', $langCode)
    //         ->first()
    //         ?? $this->translations->first(); // fallback to primary
    // }
    // public function translation(): HasOne
    // {
    //     return $this->hasOne(ArticleTranslation::class)
    //         ->where('language.code', 'en') // or use primary_language_id
    //         ->with('language');
    // }
    public function translation(): HasOne
    {
        return $this->hasOne(ArticleTranslation::class)
            ->whereHas('language', fn($q) => $q->where('code', 'en'));
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
                     ->where('published_at', '<=', now());
    }

    public function scopeBreaking($query)
    {
        return $query->where('is_breaking', true)->published();
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true)->published();
    }

    public function scopeForChannel($query, int $channelId)
    {
        return $query->where('channel_id', $channelId);
    }

    public function scopeForCategory($query, int $categoryId)
    {
        return $query->where('main_category_id', $categoryId);
    }
}
