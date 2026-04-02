<?php

namespace App\Http\Resources;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ArticleResource extends JsonResource
{
    private bool $detailed;

    public function __construct($resource, bool $detailed = false)
    {
        parent::__construct($resource);
        $this->detailed = $detailed;
    }

    public function toArray(Request $request): array
    {
        $lang        = $request->get('lang', 'en');
        $translation = $this->translations
            ->firstWhere('language.code', $lang)
            ?? $this->translations->first();

        $category = $this->mainCategory;
        $image    = $this->featuredImage;
        $thumb    = $image?->variants->firstWhere('conversion_name', 'medium');

        $imageUrl = null;
        if ($thumb?->path) {
            $imageUrl = Storage::disk('s3')->url($thumb->path);
        } elseif ($image?->original_url) {
            $imageUrl = $image->original_url;
        }

        $base = [
            'id'              => $this->id,
            'slug'            => $this->slug,
            'status'          => $this->status,
            'type'            => $this->type,
            'is_breaking'     => $this->is_breaking,
            'is_featured'     => $this->is_featured,
            'view_count'      => $this->view_count,
            'published_at'    => $this->published_at?->toIso8601String(),
            'title'           => $translation?->title,
            'subtitle'        => $translation?->subtitle,
            'summary'         => $translation?->summary,
            'seo_title'       => $translation?->seo_title,
            'seo_description' => $translation?->seo_description,
            'category'        => $category ? [
                'id'   => $category->id,
                'slug' => $category->slug,
                'name' => $category->translations->first()?->name ?? $category->default_name,
            ] : null,
            'featured_image'  => $image ? [
                'url'    => $imageUrl,
                'alt'    => $image->alt_text,
                'width'  => $thumb?->width ?? $image->width,
                'height' => $thumb?->height ?? $image->height,
            ] : null,
            'author'          => $this->author ? [
                'id'           => $this->author->id,
                'display_name' => $this->author->display_name,
                'byline'       => $this->author->authorProfile?->byline,
                'avatar_url'   => $this->author->avatar?->original_url,
            ] : null,
        ];

        if ($this->detailed) {
            $base['body']            = $translation?->body;
            $base['word_count']      = $this->word_count;
            $base['allow_comments']  = $this->allow_comments;
            $base['tags']            = $this->tags->map(fn($t) => [
                'id'   => $t->id,
                'slug' => $t->slug,
                'name' => $t->translations->first()?->name ?? $t->default_name,
            ]);
            $base['all_translations'] = $this->translations->map(fn($t) => [
                'language_id'   => $t->language_id,
                'language_code' => $t->language?->code,
                'title'         => $t->title,
            ]);
            if ($this->author) {
                $base['author']['bio_short'] = $this->author->authorProfile?->bio_short;
                $base['author']['social']    = [
                    'twitter'   => $this->author->authorProfile?->twitter_url,
                    'facebook'  => $this->author->authorProfile?->facebook_url,
                    'instagram' => $this->author->authorProfile?->instagram_url,
                    'youtube'   => $this->author->authorProfile?->youtube_url,
                ];
            }
        }

        return $base;
    }
}
