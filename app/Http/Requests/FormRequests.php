<?php
// ─────────────────────────────────────────────────────────────────────────────
// FILE: app/Http/Requests/Auth/RegisterRequest.php
// ─────────────────────────────────────────────────────────────────────────────
namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'first_name'  => ['required', 'string', 'max:80'],
            'last_name'   => ['required', 'string', 'max:80'],
            'display_name'=> ['nullable', 'string', 'max:100'],
            'email'       => ['required', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'phone'       => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'password'    => ['required', 'string', 'min:8', 'confirmed'],
            'language_id' => ['nullable', 'integer', 'exists:languages,id'],
            'country'     => ['nullable', 'string', 'max:60'],
            'timezone'    => ['nullable', 'string', 'max:60'],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FILE: app/Http/Requests/Auth/LoginRequest.php
// ─────────────────────────────────────────────────────────────────────────────

class LoginRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'remember_me' => ['nullable', 'boolean'],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FILE: app/Http/Requests/Article/StoreArticleRequest.php
// ─────────────────────────────────────────────────────────────────────────────

class StoreArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('article.create');
    }

    public function rules(): array
    {
        return [
            'title'                    => ['required', 'string', 'max:320'],
            'subtitle'                 => ['nullable', 'string', 'max:320'],
            'summary'                  => ['nullable', 'string', 'max:1000'],
            'body'                     => ['required', 'string', 'min:10'],
            'language_id'              => ['required', 'integer', 'exists:languages,id'],
            'type'                     => ['nullable', 'in:news,opinion,interview,analysis,bulletin,sponsored'],
            'main_category_id'         => ['required', 'integer', 'exists:categories,id'],
            'featured_image_media_id'  => ['nullable', 'integer', 'exists:media_assets,id'],
            'tag_ids'                  => ['nullable', 'array'],
            'tag_ids.*'                => ['integer', 'exists:tags,id'],
            'secondary_category_ids'   => ['nullable', 'array'],
            'secondary_category_ids.*' => ['integer', 'exists:categories,id'],
            'allow_comments'           => ['nullable', 'boolean'],
            'seo_title'                => ['nullable', 'string', 'max:160'],
            'seo_description'          => ['nullable', 'string', 'max:320'],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FILE: app/Http/Requests/Article/UpdateArticleRequest.php
// ─────────────────────────────────────────────────────────────────────────────

class UpdateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $article = \App\Models\Article::find($this->route('id'));
        if (! $article) return false;

        $user = $this->user();

        // Author can edit their own draft/pending articles
        if ($article->author_user_id === $user->id) {
            return in_array($article->status, ['draft', 'pending_review']);
        }

        // Editors and admins can edit any article
        return $user->hasPermission('article.edit_any');
    }

    public function rules(): array
    {
        return [
            'title'                    => ['nullable', 'string', 'max:320'],
            'subtitle'                 => ['nullable', 'string', 'max:320'],
            'summary'                  => ['nullable', 'string', 'max:1000'],
            'body'                     => ['nullable', 'string', 'min:10'],
            'language_id'              => ['nullable', 'integer', 'exists:languages,id'],
            'type'                     => ['nullable', 'in:news,opinion,interview,analysis,bulletin,sponsored'],
            'main_category_id'         => ['nullable', 'integer', 'exists:categories,id'],
            'featured_image_media_id'  => ['nullable', 'integer', 'exists:media_assets,id'],
            'tag_ids'                  => ['nullable', 'array'],
            'tag_ids.*'                => ['integer', 'exists:tags,id'],
            'secondary_category_ids'   => ['nullable', 'array'],
            'secondary_category_ids.*' => ['integer', 'exists:categories,id'],
            'allow_comments'           => ['nullable', 'boolean'],
            'seo_title'                => ['nullable', 'string', 'max:160'],
            'seo_description'          => ['nullable', 'string', 'max:320'],
            'change_summary'           => ['nullable', 'string', 'max:255'],
        ];
    }
}
