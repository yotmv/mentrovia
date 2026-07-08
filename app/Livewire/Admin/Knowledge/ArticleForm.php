<?php

namespace App\Livewire\Admin\Knowledge;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Enums\RiskLevel;
use App\Enums\SourceType;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeSource;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class ArticleForm extends Component
{
    public ?KnowledgeArticle $article = null;

    public string $title = '';

    public string $slug = '';

    public string $jurisdiction = 'TX';

    public string $category = '';

    public string $body_markdown = '';

    public ?string $source_summary = null;

    public string $risk_level = '';

    public ?string $last_verified_at = null;

    public ?string $next_review_at = null;

    public string $status = '';

    public int $version = 1;

    /** @var array<int, array<string, mixed>> */
    public array $sources = [];

    public function mount(?KnowledgeArticle $article = null): void
    {
        if ($article && $article->exists) {
            $this->authorize('update', $article);
            $this->article = $article;
            $this->title = $article->title;
            $this->slug = $article->slug;
            $this->jurisdiction = $article->jurisdiction;
            $this->category = $article->category->value;
            $this->body_markdown = $article->body_markdown;
            $this->source_summary = $article->source_summary;
            $this->risk_level = $article->risk_level->value;
            $this->last_verified_at = $article->last_verified_at?->format('Y-m-d');
            $this->next_review_at = $article->next_review_at?->format('Y-m-d');
            $this->status = $article->status->value;
            $this->version = $article->version;

            $this->sources = $article->sources->map(fn (KnowledgeSource $s) => [
                'id' => $s->id,
                'source_name' => $s->source_name,
                'source_url' => $s->source_url,
                'source_type' => $s->source_type->value,
                'retrieved_at' => $s->retrieved_at?->format('Y-m-d'),
                'effective_date' => $s->effective_date?->format('Y-m-d'),
                'notes' => $s->notes,
            ])->toArray();
        } else {
            $this->authorize('create', KnowledgeArticle::class);
        }
    }

    public function updatedTitle(string $value): void
    {
        if (! $this->article || ! $this->slug) {
            $this->slug = Str::slug($value);
        }
    }

    public function addSource(): void
    {
        $this->sources[] = [
            'id' => null,
            'source_name' => '',
            'source_url' => '',
            'source_type' => SourceType::StateAgency->value,
            'retrieved_at' => null,
            'effective_date' => null,
            'notes' => null,
        ];
    }

    public function removeSource(int $index): void
    {
        unset($this->sources[$index]);
        $this->sources = array_values($this->sources);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
            'jurisdiction' => ['required', 'string', 'max:10'],
            'category' => ['required', 'string', 'in:'.implode(',', array_column(ArticleCategory::cases(), 'value'))],
            'body_markdown' => ['required', 'string'],
            'source_summary' => ['nullable', 'string'],
            'risk_level' => ['required', 'string', 'in:'.implode(',', array_column(RiskLevel::cases(), 'value'))],
            'last_verified_at' => ['nullable', 'date'],
            'next_review_at' => ['nullable', 'date'],
            'status' => ['required', 'string', 'in:'.implode(',', array_column(ArticleStatus::cases(), 'value'))],
            'version' => ['required', 'integer', 'min:1'],
            'sources' => ['array'],
            'sources.*.id' => ['nullable', 'integer'],
            'sources.*.source_name' => ['required', 'string', 'max:255'],
            'sources.*.source_url' => ['required', 'url', 'max:500'],
            'sources.*.source_type' => ['required', 'string', 'in:'.implode(',', array_column(SourceType::cases(), 'value'))],
            'sources.*.retrieved_at' => ['nullable', 'date'],
            'sources.*.effective_date' => ['nullable', 'date'],
            'sources.*.notes' => ['nullable', 'string'],
        ]);

        $riskLevel = RiskLevel::from($validated['risk_level']);
        $status = ArticleStatus::from($validated['status']);

        if ($status === ArticleStatus::Published && $riskLevel === RiskLevel::High && empty($this->sources)) {
            $this->addError('sources', 'At least one source is required for published high-risk articles.');

            return;
        }

        $data = [
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'jurisdiction' => $validated['jurisdiction'],
            'category' => $validated['category'],
            'body_markdown' => $validated['body_markdown'],
            'source_summary' => $validated['source_summary'],
            'risk_level' => $riskLevel,
            'last_verified_at' => $validated['last_verified_at'],
            'next_review_at' => $validated['next_review_at'],
            'status' => $status,
            'version' => $validated['version'],
        ];

        if ($this->article && $this->article->exists) {
            $this->authorize('update', $this->article);
            $this->article->update($data);
            $article = $this->article->fresh();
        } else {
            $this->authorize('create', KnowledgeArticle::class);
            $article = KnowledgeArticle::create($data);
        }

        $existingIds = collect($this->sources)->pluck('id')->filter()->values()->all();
        $article->sources()->whereNotIn('id', $existingIds)->delete();

        foreach ($this->sources as $sourceData) {
            if (! empty($sourceData['id'])) {
                $article->sources()->where('id', $sourceData['id'])->update([
                    'source_name' => $sourceData['source_name'],
                    'source_url' => $sourceData['source_url'],
                    'source_type' => $sourceData['source_type'],
                    'retrieved_at' => $sourceData['retrieved_at'],
                    'effective_date' => $sourceData['effective_date'],
                    'notes' => $sourceData['notes'],
                ]);
            } else {
                $article->sources()->create([
                    'source_name' => $sourceData['source_name'],
                    'source_url' => $sourceData['source_url'],
                    'source_type' => $sourceData['source_type'],
                    'retrieved_at' => $sourceData['retrieved_at'],
                    'effective_date' => $sourceData['effective_date'],
                    'notes' => $sourceData['notes'],
                ]);
            }
        }

        Flux::toast(__('Article saved.'), variant: 'success');

        $this->redirectRoute('admin.knowledge.articles.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.knowledge.article-form', [
            'categories' => ArticleCategory::cases(),
            'riskLevels' => RiskLevel::cases(),
            'statuses' => ArticleStatus::cases(),
            'sourceTypes' => SourceType::cases(),
        ]);
    }
}
