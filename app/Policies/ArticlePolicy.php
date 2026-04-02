<?php

namespace App\Policies;

use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArticlePolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('article.create');
    }

    public function update(User $user, Article $article): bool
    {
        if ($user->hasPermission('article.edit_any')) return true;

        if ($user->hasPermission('article.edit_own') && $article->author_user_id === $user->id) {
            return in_array($article->status, ['draft', 'pending_review']);
        }

        return false;
    }

    public function publish(User $user, Article $article): bool
    {
        if ($user->hasPermission('article.publish')) return true;

        // Authors with self-publish enabled can publish their own articles
        $profile = DB::table('author_profiles')->where('user_id', $user->id)->first();
        return $profile?->can_self_publish && $article->author_user_id === $user->id;
    }

    public function delete(User $user, Article $article): bool
    {
        return $user->hasPermission('article.delete');
    }

    public function setBreaking(User $user, Article $article): bool
    {
        return $user->hasPermission('article.set_breaking');
    }
}
