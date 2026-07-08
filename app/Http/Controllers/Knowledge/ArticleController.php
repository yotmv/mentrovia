<?php

namespace App\Http\Controllers\Knowledge;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Http\Controllers\Controller;
use App\Models\KnowledgeArticle;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function index(Request $request): View
    {
        $category = $request->query('category');
        $status = $request->query('status');

        $articles = KnowledgeArticle::query()
            ->with('sources')
            ->when($category, fn ($query) => $query->where('category', $category))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('title')
            ->get();

        return view('pages.knowledge.index', [
            'articles' => $articles,
            'categories' => ArticleCategory::cases(),
            'statuses' => ArticleStatus::cases(),
            'selectedCategory' => $category,
            'selectedStatus' => $status,
        ]);
    }

    public function show(string $slug): View
    {
        $article = KnowledgeArticle::where('slug', $slug)
            ->with('sources')
            ->firstOrFail();

        return view('pages.knowledge.show', [
            'article' => $article,
        ]);
    }
}
