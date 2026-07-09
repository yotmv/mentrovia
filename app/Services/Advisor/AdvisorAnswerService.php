<?php

namespace App\Services\Advisor;

use App\Ai\Text\Contracts\TextRoleGenerator;
use App\Ai\Text\TextGenerationRequest;
use App\Enums\ArticleStatus;
use App\Enums\FreshnessStatus;
use App\Enums\RiskLevel;
use App\Enums\TextGenerationRole;
use App\Enums\ValidationDecision;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\Business;
use App\Models\KnowledgeArticle;
use App\Models\User;
use App\Services\ComplianceValidation\ValidationPipeline;
use App\Services\ComplianceValidation\ValidationPipelineResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AdvisorAnswerService
{
    public function __construct(
        protected TextRoleGenerator $generator,
        protected ValidationPipeline $validation,
    ) {}

    public function answer(User $user, Business $business, string $question): AgentConversationMessage
    {
        $business->loadMissing('profileAnswers');

        $conversation = $this->conversationFor($user);
        $question = trim($question);

        $this->storeMessage($conversation, $user, 'user', $question, [
            'business_id' => $business->id,
        ]);

        $articles = $this->relevantArticles($question);
        $validationResults = $this->validationResults($articles, $user, $business, $question);
        $answer = $articles->isEmpty()
            ? $this->emptyKnowledgeAnswer()
            : $this->generateAnswer($conversation, $business, $question, $articles, $validationResults);

        $conversation->touch();

        return $this->storeMessage($conversation, $user, 'assistant', $answer['direct_answer'], [
            'answer' => $answer,
        ]);
    }

    public function conversationFor(User $user): AgentConversation
    {
        return AgentConversation::query()
            ->whereBelongsTo($user)
            ->where('title', 'Advisor Q&A')
            ->latest('updated_at')
            ->first()
            ?? AgentConversation::create([
                'user_id' => $user->id,
                'title' => 'Advisor Q&A',
            ]);
    }

    public function relevantArticles(string $question): Collection
    {
        $tokens = $this->tokens($question);

        $ranked = KnowledgeArticle::query()
            ->where('status', '!=', ArticleStatus::Archived->value)
            ->get()
            ->map(fn (KnowledgeArticle $article): array => [
                'article' => $article,
                'score' => $this->scoreArticle($article, $tokens),
            ])
            ->filter(fn (array $scored): bool => $scored['score'] > 0)
            ->sortByDesc('score')
            ->take(3)
            ->values();

        $ids = $ranked->pluck('article.id')->values();

        return KnowledgeArticle::query()
            ->with('sources')
            ->whereKey($ids)
            ->get()
            ->sortBy(function (KnowledgeArticle $article) use ($ids): int {
                $index = $ids->search($article->getKey());

                return $index === false ? PHP_INT_MAX : $index;
            })
            ->values();
    }

    /**
     * @param  Collection<int, KnowledgeArticle>  $articles
     * @return array<int, ValidationPipelineResult>
     */
    protected function validationResults(Collection $articles, User $user, Business $business, string $question): array
    {
        return $articles
            ->filter(fn (KnowledgeArticle $article): bool => $this->needsValidation($article))
            ->map(fn (KnowledgeArticle $article): ValidationPipelineResult => $this->validation->validate($article, $user, $business, $question))
            ->values()
            ->all();
    }

    protected function needsValidation(KnowledgeArticle $article): bool
    {
        $freshness = $article->freshnessStatus();

        return $article->risk_level === RiskLevel::High
            || $freshness === FreshnessStatus::Stale
            || $freshness === FreshnessStatus::MissingSources;
    }

    /**
     * @param  Collection<int, KnowledgeArticle>  $articles
     * @param  array<int, ValidationPipelineResult>  $validationResults
     * @return array<string, mixed>
     */
    protected function generateAnswer(
        AgentConversation $conversation,
        Business $business,
        string $question,
        Collection $articles,
        array $validationResults,
    ): array {
        $result = $this->generator->generate(TextGenerationRequest::make(
            TextGenerationRole::AdvisorAnswer,
            $this->answerPrompt(),
            [
                'question' => $question,
                'business' => $this->businessContext($business),
                'knowledge' => $articles->map(fn (KnowledgeArticle $article): array => $this->articleContext($article))->all(),
                'validation' => $this->validationContext($validationResults),
                'recent_history' => $this->recentHistory($conversation),
            ],
        ));

        $payload = $this->decodePayload($result->text);
        $sources = $articles->map(fn (KnowledgeArticle $article): array => $this->sourceFreshness($article))->all();

        return [
            'direct_answer' => $this->stringOrDefault(
                Arr::get($payload, 'direct_answer'),
                Str::limit($result->text, 2000, ''),
            ),
            'checklist' => $this->strings(Arr::get($payload, 'checklist', [])),
            'caveats' => $this->withDefaultCaveat($this->strings(Arr::get($payload, 'caveats', []))),
            'confidence' => $this->confidence(Arr::get($payload, 'confidence')),
            'source_freshness' => $sources,
            'professional_review_flags' => $this->professionalReviewFlags($articles, $validationResults, Arr::get($payload, 'professional_review_flags', [])),
            'provider' => $result->provider,
            'model' => $result->model,
            'config_version' => $result->configVersion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyKnowledgeAnswer(): array
    {
        return [
            'direct_answer' => __('I could not find enough cached Mentrovia knowledge to answer this safely yet. Use the Knowledge area or update your company profile, then verify any compliance question with the relevant agency or a qualified professional.'),
            'checklist' => [],
            'caveats' => [__('No matching cached knowledge article was found for this question.')],
            'confidence' => 20,
            'source_freshness' => [],
            'professional_review_flags' => [__('Review with a qualified professional before acting.')],
            'provider' => 'none',
            'model' => 'none',
            'config_version' => 'none',
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function storeMessage(AgentConversation $conversation, User $user, string $role, string $content, array $meta): AgentConversationMessage
    {
        return AgentConversationMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'agent' => 'advisor',
            'role' => $role,
            'content' => $content,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => $meta,
        ]);
    }

    protected function answerPrompt(): string
    {
        return 'Answer this Texas small-business question from the provided business profile, cached knowledge, sources, and validation results. Return only JSON with: direct_answer, checklist, caveats, confidence, and professional_review_flags. Keep the answer practical, scoped to the profile, cite source freshness in the wording, and never present legal, tax, payroll, or accounting guidance as a substitute for a qualified professional.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function businessContext(Business $business): array
    {
        return [
            'id' => $business->id,
            'display_name' => $business->displayName(),
            'stage' => $business->stage?->value,
            'legal_structure' => $business->legal_structure->value,
            'tax_classification' => $business->tax_classification,
            'industry' => $business->industry,
            'city' => $business->city,
            'county' => $business->county,
            'state' => $business->state,
            'employee_count' => $business->employee_count,
            'uses_contractors' => $business->uses_contractors,
            'sells_taxable_goods' => $business->sells_taxable_goods->value,
            'sells_taxable_services' => $business->sells_taxable_services->value,
            'has_sales_tax_permit' => $business->has_sales_tax_permit->value,
            'has_payroll' => $business->has_payroll,
            'profile_answers' => $business->profileAnswers
                ->mapWithKeys(fn ($answer): array => [$answer->question_key => $answer->answer_value])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function articleContext(KnowledgeArticle $article): array
    {
        return [
            ...$this->sourceFreshness($article),
            'category' => $article->category->value,
            'body_markdown' => Str::limit($article->body_markdown, 5000, ''),
            'source_summary' => $article->source_summary,
            'sources' => $article->sources
                ->map(fn ($source): array => [
                    'name' => $source->source_name,
                    'url' => $source->source_url,
                    'type' => $source->source_type->value,
                    'retrieved_at' => $source->retrieved_at?->toDateString(),
                    'effective_date' => $source->effective_date?->toDateString(),
                    'notes' => $source->notes,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sourceFreshness(KnowledgeArticle $article): array
    {
        return [
            'article_id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'risk_level' => $article->risk_level->value,
            'freshness_status' => $article->freshnessStatus()->value,
            'freshness_label' => $article->freshnessStatus()->label(),
            'last_verified_at' => $article->last_verified_at?->toDateString(),
            'next_review_at' => $article->next_review_at?->toDateString(),
            'source_count' => $article->sources->count(),
        ];
    }

    /**
     * @param  array<int, ValidationPipelineResult>  $validationResults
     * @return array<int, array<string, mixed>>
     */
    protected function validationContext(array $validationResults): array
    {
        return collect($validationResults)
            ->map(fn (ValidationPipelineResult $result): array => [
                'article_id' => $result->run->knowledge_article_id,
                'status' => $result->status->value,
                'decision' => $result->decision->value,
                'decision_label' => $result->decision->label(),
                'confidence' => $result->run->confidence,
                'flags' => $result->run->flags ?? [],
                'concerns' => $result->run->concerns ?? [],
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function recentHistory(AgentConversation $conversation): array
    {
        return $conversation->messages()
            ->latest()
            ->limit(6)
            ->get()
            ->reverse()
            ->map(fn (AgentConversationMessage $message): array => [
                'role' => $message->role,
                'content' => Str::limit($message->content, 1000, ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $tokens
     */
    protected function scoreArticle(KnowledgeArticle $article, array $tokens): int
    {
        $title = Str::lower($article->title.' '.$article->category->label());
        $body = Str::lower($article->body_markdown.' '.$article->source_summary);

        return collect($tokens)->sum(function (string $token) use ($title, $body): int {
            return (Str::contains($title, $token) ? 4 : 0)
                + (Str::contains($body, $token) ? 1 : 0);
        });
    }

    /**
     * @return array<int, string>
     */
    protected function tokens(string $question): array
    {
        $parts = preg_split('/[^a-z0-9]+/', Str::lower($question)) ?: [];
        $stopWords = ['about', 'after', 'before', 'business', 'could', 'does', 'have', 'need', 'should', 'what', 'when', 'with', 'your'];

        return collect($parts)
            ->filter(fn (string $part): bool => mb_strlen($part) >= 3 && ! in_array($part, $stopWords, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodePayload(string $text): array
    {
        $json = Str::of($text)
            ->trim()
            ->replaceMatches('/^```(?:json)?\s*/', '')
            ->replaceMatches('/\s*```$/', '')
            ->toString();

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : ['direct_answer' => $text];
    }

    protected function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && filled($value) ? $value : $default;
    }

    protected function confidence(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(100, (int) $value)) : null;
    }

    /**
     * @return array<int, string>
     */
    protected function strings(mixed $value): array
    {
        return collect(Arr::wrap($value))
            ->filter(fn (mixed $item): bool => is_string($item) && filled($item))
            ->map(fn (string $item): string => trim($item))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $caveats
     * @return array<int, string>
     */
    protected function withDefaultCaveat(array $caveats): array
    {
        $caveats[] = __('This is educational guidance, not legal, tax, payroll, or accounting advice.');

        return array_values(array_unique($caveats));
    }

    /**
     * @param  Collection<int, KnowledgeArticle>  $articles
     * @param  array<int, ValidationPipelineResult>  $validationResults
     * @return array<int, string>
     */
    protected function professionalReviewFlags(Collection $articles, array $validationResults, mixed $generatedFlags): array
    {
        $flags = $this->strings($generatedFlags);

        foreach ($articles as $article) {
            if ($article->risk_level === RiskLevel::High) {
                $flags[] = __('High-risk compliance topic: review with a qualified professional.');
            }
        }

        foreach ($validationResults as $result) {
            if (in_array($result->decision, [
                ValidationDecision::NeedsProfessionalReview,
                ValidationDecision::NeedsSourceRefresh,
                ValidationDecision::ConflictingSources,
                ValidationDecision::AdminReviewRequired,
            ], true)) {
                $flags[] = $result->decision->label();
            }
        }

        return array_values(array_unique($flags));
    }
}
