<?php

namespace App\Services\ComplianceValidation;

use App\Enums\FreshnessStatus;
use App\Enums\RiskLevel;
use App\Enums\ValidationDecision;
use App\Models\KnowledgeArticle;
use Illuminate\Support\Str;

class ValidationGuardrail
{
    /**
     * @return array{decision: ValidationDecision|null, flags: array<int, string>, concerns: array<int, string>}
     */
    public function inspect(KnowledgeArticle $article): array
    {
        $article->loadMissing('sources');

        $findings = [
            'decision' => null,
            'flags' => [],
            'concerns' => [],
        ];

        $freshness = $article->freshnessStatus();

        if ($freshness === FreshnessStatus::MissingSources) {
            $findings = $this->addFinding(
                $findings,
                ValidationDecision::NeedsSourceRefresh,
                'missing_sources',
                'Article has no source records attached.',
            );
        } elseif ($freshness === FreshnessStatus::Stale) {
            $findings = $this->addFinding(
                $findings,
                ValidationDecision::NeedsSourceRefresh,
                'stale_sources',
                'Article source review date has passed or is missing.',
            );
        }

        if ($this->isMissingDisclaimer($article->body_markdown)) {
            $findings = $this->addFinding(
                $findings,
                ValidationDecision::ApprovedWithCaveats,
                'missing_disclaimer',
                'Compliance guidance is missing the standard legal, tax, payroll, or accounting disclaimer.',
            );
        }

        if ($this->hasUnsupportedNumericClaim($article)) {
            $findings = $this->addFinding(
                $findings,
                ValidationDecision::NeedsSourceRefresh,
                'unsupported_numeric_claim',
                'Article includes deadline, rate, threshold, or dollar-amount language without matching source support.',
            );
        }

        if ($this->hasProfessionalCertaintyClaim($article->body_markdown)) {
            $findings = $this->addFinding(
                $findings,
                ValidationDecision::NeedsProfessionalReview,
                'professional_review_certainty',
                'Legal, tax, payroll, or accounting guidance uses overly certain language.',
            );
        }

        if ($article->risk_level === RiskLevel::High && $freshness !== FreshnessStatus::Fresh) {
            $findings = $this->addFinding(
                $findings,
                ValidationDecision::NeedsSourceRefresh,
                'high_risk_not_fresh',
                'High-risk compliance content cannot be approved as current while stale, due soon, or missing sources.',
            );
        }

        return [
            'decision' => $findings['decision'],
            'flags' => array_values(array_unique($findings['flags'])),
            'concerns' => array_values(array_unique($findings['concerns'])),
        ];
    }

    /**
     * @param  array{decision: ValidationDecision|null, flags: array<int, string>, concerns: array<int, string>}  $findings
     * @return array{decision: ValidationDecision|null, flags: array<int, string>, concerns: array<int, string>}
     */
    protected function addFinding(array $findings, ValidationDecision $decision, string $flag, string $concern): array
    {
        $findings['decision'] = $this->moreConservative($findings['decision'], $decision);
        $findings['flags'][] = $flag;
        $findings['concerns'][] = $concern;

        return $findings;
    }

    protected function moreConservative(?ValidationDecision $current, ValidationDecision $candidate): ValidationDecision
    {
        if (! $current instanceof ValidationDecision) {
            return $candidate;
        }

        return $this->rank($candidate) > $this->rank($current) ? $candidate : $current;
    }

    protected function rank(ValidationDecision $decision): int
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

    protected function isMissingDisclaimer(string $body): bool
    {
        $normalized = Str::of($body)->lower()->squish()->toString();

        return ! Str::contains($normalized, 'not legal')
            || ! Str::contains($normalized, 'tax')
            || ! Str::contains($normalized, 'qualified professional');
    }

    protected function hasUnsupportedNumericClaim(KnowledgeArticle $article): bool
    {
        if (! preg_match('/(?:\b(?:deadline|due|rate|threshold|no-tax-due|penalt|fee|percent|percentage)\b|\$\s?\d|[0-9]+(?:\.[0-9]+)?\s?%)/i', $article->body_markdown)) {
            return false;
        }

        $sourceText = collect([
            $article->source_summary,
            ...$article->sources->flatMap(fn ($source): array => [
                $source->source_name,
                $source->notes,
                $source->retrieved_at?->toDateString(),
                $source->effective_date?->toDateString(),
            ])->all(),
        ])
            ->filter()
            ->implode(' ');

        return ! preg_match('/\b(?:deadline|due|rate|threshold|tax|fee|percent|retrieved|effective)\b/i', $sourceText);
    }

    protected function hasProfessionalCertaintyClaim(string $body): bool
    {
        return (bool) preg_match(
            '/\b(?:must|always|never|guarantees?|will owe|required to)\b.{0,80}\b(?:legal|law|tax|payroll|accounting|franchise tax|sales tax|employee|contractor)\b/i',
            $body,
        );
    }
}
