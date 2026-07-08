<?php

namespace App\Policies;

use App\Models\KnowledgeArticle;
use App\Models\User;

class KnowledgeArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, KnowledgeArticle $article): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, KnowledgeArticle $article): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, KnowledgeArticle $article): bool
    {
        return $user->is_admin;
    }
}
