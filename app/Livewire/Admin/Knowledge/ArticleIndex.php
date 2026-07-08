<?php

namespace App\Livewire\Admin\Knowledge;

use App\Enums\ArticleStatus;
use App\Models\KnowledgeArticle;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ArticleIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public string $statusFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, KnowledgeArticle>
     */
    #[Computed]
    public function articles(): LengthAwarePaginator
    {
        return KnowledgeArticle::query()
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->withCount('sources')
            ->orderByDesc('updated_at')
            ->paginate(15);
    }

    public function archive(int $id): void
    {
        $article = KnowledgeArticle::findOrFail($id);
        $this->authorize('update', $article);

        $article->update(['status' => ArticleStatus::Archived]);

        Flux::toast(__('Article archived.'), variant: 'success');
    }

    public function markStale(int $id): void
    {
        $article = KnowledgeArticle::findOrFail($id);
        $this->authorize('update', $article);

        $article->update([
            'status' => ArticleStatus::NeedsReview,
            'next_review_at' => now(),
        ]);

        Flux::toast(__('Article marked as needing review.'), variant: 'success');
    }

    public function requestRevalidation(int $id): void
    {
        $article = KnowledgeArticle::findOrFail($id);
        $this->authorize('update', $article);

        $article->update(['status' => ArticleStatus::NeedsReview]);

        Flux::toast(__('Revalidation requested. The validation pipeline will process this article when available.'), variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.admin.knowledge.article-index');
    }
}
