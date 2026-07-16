<?php

namespace App\Http\Controllers\Knowledge;

use App\Enums\ArticleCategory;
use App\Http\Controllers\Controller;
use App\Models\KnowledgeArticle;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function index(Request $request): View
    {
        $category = $request->query('category');
        $articles = KnowledgeArticle::query()
            ->published()
            ->with('sources')
            ->when($category, fn ($query) => $query->where('category', $category))
            ->orderBy('title')
            ->get();

        return view('pages.knowledge.index', [
            'articles' => $articles,
            'categories' => ArticleCategory::cases(),
            'selectedCategory' => $category,
        ]);
    }

    public function show(string $slug): View
    {
        $article = KnowledgeArticle::published()
            ->where('slug', $slug)
            ->with('sources')
            ->firstOrFail();

        return view('pages.knowledge.show', [
            'article' => $article,
        ]);
    }
}
