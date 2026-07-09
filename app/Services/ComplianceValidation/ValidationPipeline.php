<?php

namespace App\Services\ComplianceValidation;

use App\Ai\Text\Contracts\TextRoleGenerator;
use App\Ai\Text\TextGenerationRequest;
use App\Enums\TextGenerationRole;
use App\Enums\ValidationDecision;
use App\Enums\ValidationRunStatus;
use App\Models\Business;
use App\Models\KnowledgeArticle;
use App\Models\User;
use App\Models\ValidationRun;
use App\Models\ValidationVote;
use Illuminate\Support\Facades\DB;
use Throwable;

class ValidationPipeline
{
    /**
     * @var array<int, TextGenerationRole>
     */
    protected array $reviewerRoles = [
        TextGenerationRole::ValidatorFactual,
        TextGenerationRole::ValidatorContradiction,
        TextGenerationRole::ValidatorUserFit,
    ];

    public function __construct(
        protected TextRoleGenerator $generator,
        protected ValidationGuardrail $guardrail,
    ) {}

    public function validate(
        KnowledgeArticle $article,
        ?User $user = null,
        ?Business $business = null,
        ?string $question = null,
    ): ValidationPipelineResult {
        $article->loadMissing('sources');
        $business?->loadMissing('profileAnswers');

        $guardrails = $this->guardrail->inspect($article);
        $normalizedRequest = $this->normalizedRequest($article, $question, $guardrails);
        $contextSnapshot = $this->contextSnapshot($article, $business, $guardrails);
        $userId = $business instanceof Business ? $business->user_id : $user?->id;

        $run = ValidationRun::create([
            'knowledge_article_id' => $article->id,
            'user_id' => $userId,
            'business_id' => $business?->id,
            'normalized_request' => $normalizedRequest,
            'context_snapshot' => $contextSnapshot,
            'status' => ValidationRunStatus::Running,
            'flags' => $guardrails['flags'],
            'concerns' => $guardrails['concerns'],
            'metadata' => [
                'pipeline' => 'compliance-validation-v1',
                'guardrail_decision' => $guardrails['decision']?->value,
            ],
            'started_at' => now(),
        ]);

        try {
            $reviewerResponses = [];

            foreach ($this->reviewerRoles as $role) {
                $reviewerResponses[] = $this->storeVote(
                    $run,
                    $this->generate($role, $normalizedRequest, $contextSnapshot, $reviewerResponses),
                );
            }

            $finalResponse = $this->storeVote(
                $run,
                $this->generate(TextGenerationRole::FinalJudge, $normalizedRequest, $contextSnapshot, $reviewerResponses),
            );

            $decision = $this->aggregateDecision($guardrails['decision'], [
                ...$reviewerResponses,
                $finalResponse,
            ]);

            $allResponses = collect($reviewerResponses)->push($finalResponse);

            $run->update([
                'status' => ValidationRunStatus::Completed,
                'aggregate_decision' => $decision,
                'final_model_role' => TextGenerationRole::FinalJudge,
                'final_provider' => $finalResponse->provider,
                'final_model' => $finalResponse->model,
                'confidence' => $this->aggregateConfidence([...$reviewerResponses, $finalResponse]),
                'flags' => $this->mergedStrings($guardrails['flags'], $allResponses->pluck('flags')->flatten()->all()),
                'concerns' => $this->mergedStrings($guardrails['concerns'], $allResponses->pluck('concerns')->flatten()->all()),
                'raw_response' => [
                    'decision' => $decision->value,
                    'final_judge' => $finalResponse->rawResponse,
                    'reviewer_votes' => collect($reviewerResponses)
                        ->map(fn (ValidationModelResponse $response): array => [
                            'role' => $response->role->value,
                            'decision' => $response->decision->value,
                            'confidence' => $response->confidence,
                        ])
                        ->all(),
                ],
                'completed_at' => now(),
            ]);

            return new ValidationPipelineResult($run->refresh(), ValidationRunStatus::Completed, $decision);
        } catch (Throwable $e) {
            report($e);

            $run->update([
                'status' => ValidationRunStatus::Failed,
                'aggregate_decision' => ValidationDecision::AdminReviewRequired,
                'flags' => $this->mergedStrings($guardrails['flags'], ['pipeline_error']),
                'concerns' => $this->mergedStrings($guardrails['concerns'], ['Validation pipeline failed before a reliable decision was reached.']),
                'raw_response' => [
                    'exception' => $e::class,
                    'message' => 'Validation pipeline failed before completion.',
                ],
                'completed_at' => now(),
            ]);

            return new ValidationPipelineResult($run->refresh(), ValidationRunStatus::Failed, ValidationDecision::AdminReviewRequired);
        }
    }

    /**
     * @param  array<string, mixed>  $normalizedRequest
     * @param  array<string, mixed>  $contextSnapshot
     * @param  array<int, ValidationModelResponse>  $priorResponses
     */
    protected function generate(
        TextGenerationRole $role,
        array $normalizedRequest,
        array $contextSnapshot,
        array $priorResponses,
    ): ValidationModelResponse {
        return ValidationModelResponse::fromTextResult(
            $this->generator->generate(TextGenerationRequest::make(
                $role,
                $this->promptFor($role),
                [
                    'request' => $normalizedRequest,
                    'context' => $contextSnapshot,
                    'prior_votes' => collect($priorResponses)
                        ->map(fn (ValidationModelResponse $response): array => [
                            'role' => $response->role->value,
                            'decision' => $response->decision->value,
                            'confidence' => $response->confidence,
                            'flags' => $response->flags,
                            'concerns' => $response->concerns,
                        ])
                        ->all(),
                ],
            )),
        );
    }

    protected function promptFor(TextGenerationRole $role): string
    {
        $roleLabel = $role === TextGenerationRole::FinalJudge ? 'final judge' : $role->label();

        return "Act as the {$roleLabel} for a Texas small-business compliance validation run. Return only JSON with: decision, confidence, flags, concerns, and rationale. Use one decision value from: approved_current, approved_with_caveats, needs_source_refresh, needs_professional_review, conflicting_sources, not_enough_information, admin_review_required.";
    }

    protected function storeVote(ValidationRun $run, ValidationModelResponse $response): ValidationModelResponse
    {
        DB::transaction(function () use ($run, $response): void {
            ValidationVote::create([
                'validation_run_id' => $run->id,
                'model_role' => $response->role,
                'provider' => $response->provider,
                'model' => $response->model,
                'vote' => $response->decision,
                'confidence' => $response->confidence,
                'flags' => $response->flags,
                'concerns' => $response->concerns,
                'raw_response' => $response->rawResponse,
                'metadata' => $response->metadata,
                'responded_at' => now(),
            ]);
        });

        return $response;
    }

    /**
     * @param  array<int, ValidationModelResponse>  $responses
     */
    protected function aggregateDecision(?ValidationDecision $guardrailDecision, array $responses): ValidationDecision
    {
        return collect([
            $guardrailDecision,
            ...array_map(fn (ValidationModelResponse $response): ValidationDecision => $response->decision, $responses),
        ])
            ->filter()
            ->sortByDesc(fn (ValidationDecision $decision): int => $this->decisionRank($decision))
            ->first() ?? ValidationDecision::NotEnoughInformation;
    }

    protected function decisionRank(ValidationDecision $decision): int
    {
        return match ($decision) {
            ValidationDecision::ApprovedCurrent => 0,
            ValidationDecision::ApprovedWithCaveats => 10,
            ValidationDecision::NotEnoughInformation => 20,
            ValidationDecision::NeedsProfessionalReview => 30,
            ValidationDecision::NeedsSourceRefresh => 40,
            ValidationDecision::ConflictingSources => 50,
            ValidationDecision::AdminReviewRequired => 60,
        };
    }

    /**
     * @param  array<int, ValidationModelResponse>  $responses
     */
    protected function aggregateConfidence(array $responses): ?int
    {
        $confidences = collect($responses)
            ->pluck('confidence')
            ->filter(fn (?int $confidence): bool => $confidence !== null);

        if ($confidences->isEmpty()) {
            return null;
        }

        return (int) round($confidences->average());
    }

    /**
     * @param  array{decision: ValidationDecision|null, flags: array<int, string>, concerns: array<int, string>}  $guardrails
     * @return array<string, mixed>
     */
    protected function normalizedRequest(KnowledgeArticle $article, ?string $question, array $guardrails): array
    {
        return [
            'article' => [
                'id' => $article->id,
                'slug' => $article->slug,
                'title' => $article->title,
                'jurisdiction' => $article->jurisdiction,
                'category' => $article->category->value,
                'risk_level' => $article->risk_level->value,
                'status' => $article->status->value,
                'version' => $article->version,
                'freshness_status' => $article->freshnessStatus()->value,
                'last_verified_at' => $article->last_verified_at?->toDateString(),
                'next_review_at' => $article->next_review_at?->toDateString(),
                'source_summary' => $article->source_summary,
                'body_markdown' => $article->body_markdown,
            ],
            'question' => $question,
            'guardrails' => [
                'decision' => $guardrails['decision']?->value,
                'flags' => $guardrails['flags'],
                'concerns' => $guardrails['concerns'],
            ],
        ];
    }

    /**
     * @param  array{decision: ValidationDecision|null, flags: array<int, string>, concerns: array<int, string>}  $guardrails
     * @return array<string, mixed>
     */
    protected function contextSnapshot(KnowledgeArticle $article, ?Business $business, array $guardrails): array
    {
        return [
            'business' => $business ? [
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
            ] : null,
            'profile_answers' => $business
                ? $business->profileAnswers
                    ->mapWithKeys(fn ($answer): array => [$answer->question_key => $answer->answer_value])
                    ->all()
                : [],
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
            'guardrails' => [
                'decision' => $guardrails['decision']?->value,
                'flags' => $guardrails['flags'],
                'concerns' => $guardrails['concerns'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  ...$groups
     * @return array<int, string>
     */
    protected function mergedStrings(array ...$groups): array
    {
        return collect($groups)
            ->flatten()
            ->filter(fn (mixed $value): bool => is_string($value) && filled($value))
            ->unique()
            ->values()
            ->all();
    }
}
