<?php

namespace App\Enums;

enum TextGenerationRole: string
{
    case Classifier = 'classifier';
    case ValidatorFactual = 'validator_factual';
    case ValidatorContradiction = 'validator_contradiction';
    case ValidatorUserFit = 'validator_user_fit';
    case FinalJudge = 'final_judge';
    case AdvisorAnswer = 'advisor_answer';
    case BrandCopy = 'brand_copy';
    case AdCopy = 'ad_copy';

    public function label(): string
    {
        return match ($this) {
            self::Classifier => 'Classifier',
            self::ValidatorFactual => 'Validator: factual',
            self::ValidatorContradiction => 'Validator: contradiction',
            self::ValidatorUserFit => 'Validator: user fit',
            self::FinalJudge => 'Final judge',
            self::AdvisorAnswer => 'Advisor answer',
            self::BrandCopy => 'Brand copy',
            self::AdCopy => 'Ad copy',
        };
    }

    public function usesHumanVoiceGuidance(): bool
    {
        return match ($this) {
            self::BrandCopy, self::AdCopy => true,
            default => false,
        };
    }
}
