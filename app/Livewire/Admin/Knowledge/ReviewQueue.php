<?php

namespace App\Livewire\Admin\Knowledge;

use App\Enums\ArticleStatus;
use App\Enums\ValidationDecision;
use App\Enums\ValidationRunStatus;
use App\Models\KnowledgeArticle;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ReviewQueue extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public string $decisionFilter = '';

    /** @var array<int, string> */
    public array $reviewNotes = [];

    public function mount(): void
    {
        $this->authorize('viewAny', KnowledgeArticle::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDecisionFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, KnowledgeArticle>
     */
    #[Computed]
    public function articles(): LengthAwarePaginator
    {
        return $this->queueQuery()
            ->with([
                'latestValidationRun.votes',
                'sources',
            ])
            ->withCount(['sources', 'validationRuns'])
            ->orderByDesc('updated_at')
            ->paginate(10);
    }

    public function approveCurrent(int $id): void
    {
        $article = KnowledgeArticle::findOrFail($id);
        $this->authorize('update', $article);

        $article->update([
            'status' => ArticleStatus::Published,
            'last_verified_at' => now(),
            'next_review_at' => now()->addMonths(3),
            'admin_reviewed_at' => now(),
            'revalidation_requested_at' => null,
        ]);

        Flux::toast(__('Current content approved.'), variant: 'success');
    }

    public function markStale(int $id): void
    {
        $article = KnowledgeArticle::findOrFail($id);
        $this->authorize('update', $article);

        $article->update([
            'status' => ArticleStatus::NeedsReview,
            'next_review_at' => now()->subDay(),
            'admin_reviewed_at' => now(),
        ]);

        Flux::toast(__('Article marked stale.'), variant: 'success');
    }

    public function requestRevalidation(int $id): void
    {
        $article = KnowledgeArticle::findOrFail($id);
        $this->authorize('update', $article);

        $article->update([
            'status' => ArticleStatus::NeedsReview,
            'revalidation_requested_at' => now(),
        ]);

        Flux::toast(__('Revalidation requested.'), variant: 'success');
    }

    public function archive(int $id): void
    {
        $article = KnowledgeArticle::findOrFail($id);
        $this->authorize('update', $article);

        $article->update([
            'status' => ArticleStatus::Archived,
            'admin_reviewed_at' => now(),
        ]);

        Flux::toast(__('Article archived.'), variant: 'success');
    }

    public function saveReviewNotes(int $id): void
    {
        $article = KnowledgeArticle::findOrFail($id);
        $this->authorize('update', $article);

        $notes = trim((string) Arr::get($this->reviewNotes, $id, ''));

        if (mb_strlen($notes) > 5000) {
            throw ValidationException::withMessages([
                "reviewNotes.{$id}" => __('Review notes may not be greater than 5000 characters.'),
            ]);
        }

        $article->update([
            'admin_review_notes' => $notes === '' ? null : $notes,
        ]);

        Flux::toast(__('Review notes saved.'), variant: 'success');
    }

    /**
     * @return array<int, string>
     */
    public function reviewReasons(KnowledgeArticle $article): array
    {
        $reasons = [];

        if ($article->status === ArticleStatus::NeedsReview) {
            $reasons[] = __('Admin review required');
        }

        if (! $article->next_review_at || $article->next_review_at->isPast()) {
            $reasons[] = __('Stale');
        }

        $latestRun = $article->latestValidationRun;

        if ($latestRun?->status === ValidationRunStatus::Failed) {
            $reasons[] = __('Failed validation');
        }

        if ($latestRun?->aggregate_decision === ValidationDecision::ConflictingSources) {
            $reasons[] = __('Conflicting sources');
        }

        if ($latestRun?->aggregate_decision === ValidationDecision::AdminReviewRequired) {
            $reasons[] = __('Admin review decision');
        }

        return array_values(array_unique($reasons));
    }

    public function render(): View
    {
        return view('livewire.admin.knowledge.review-queue');
    }

    /**
     * @return Builder<KnowledgeArticle>
     */
    protected function queueQuery(): Builder
    {
        return KnowledgeArticle::query()
            ->where('status', '!=', ArticleStatus::Archived->value)
            ->when($this->search, fn (Builder $query): Builder => $query->where('title', 'like', "%{$this->search}%"))
            ->when($this->decisionFilter, fn (Builder $query): Builder => $query->whereHas(
                'latestValidationRun',
                fn (Builder $runQuery): Builder => $runQuery->where('aggregate_decision', $this->decisionFilter),
            ))
            ->where(function (Builder $query): void {
                $query
                    ->where('status', ArticleStatus::NeedsReview->value)
                    ->orWhereNull('next_review_at')
                    ->orWhere('next_review_at', '<', now()->startOfDay())
                    ->orWhereHas('latestValidationRun', function (Builder $runQuery): void {
                        $runQuery
                            ->where(function (Builder $reviewRunQuery): void {
                                $reviewRunQuery
                                    ->where('status', ValidationRunStatus::Failed->value)
                                    ->orWhereIn('aggregate_decision', [
                                        ValidationDecision::ConflictingSources->value,
                                        ValidationDecision::AdminReviewRequired->value,
                                    ]);
                            })
                            ->where(function (Builder $reviewedQuery): void {
                                $reviewedQuery
                                    ->whereNull('knowledge_articles.admin_reviewed_at')
                                    ->orWhereColumn('validation_runs.updated_at', '>', 'knowledge_articles.admin_reviewed_at');
                            });
                    });
            });
    }
}
