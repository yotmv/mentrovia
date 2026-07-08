<?php

$defaultProvider = env('TEXT_GENERATION_PROVIDER', 'openrouter');
$defaultModel = env('TEXT_GENERATION_MODEL', 'openrouter/auto');
$defaultTimeout = (int) env('TEXT_GENERATION_TIMEOUT', 60);

$role = function (string $prefix, string $instructions, bool $humanVoice = false) use ($defaultProvider, $defaultModel, $defaultTimeout): array {
    return [
        'provider' => env("TEXT_{$prefix}_PROVIDER", $defaultProvider),
        'model' => env("TEXT_{$prefix}_MODEL", $defaultModel),
        'timeout' => (int) env("TEXT_{$prefix}_TIMEOUT", $defaultTimeout),
        'fallbacks' => [
            [
                'provider' => env("TEXT_{$prefix}_FALLBACK_PROVIDER"),
                'model' => env("TEXT_{$prefix}_FALLBACK_MODEL"),
                'timeout' => (int) env("TEXT_{$prefix}_FALLBACK_TIMEOUT", $defaultTimeout),
            ],
        ],
        'human_voice_guidance' => $humanVoice,
        'instructions' => $instructions,
    ];
};

return [
    'version' => env('TEXT_GENERATION_CONFIG_VERSION', 'v1'),

    'default_timeout' => $defaultTimeout,

    'roles' => [
        'classifier' => $role('CLASSIFIER', <<<'INSTRUCTIONS'
        Classify the supplied business, compliance, or product text into the labels requested by the caller.
        Be terse, deterministic, and return valid JSON when the caller asks for JSON.
        INSTRUCTIONS),

        'validator_factual' => $role('VALIDATOR_FACTUAL', <<<'INSTRUCTIONS'
        Review compliance guidance for factual support. Identify unsupported deadlines, rates, thresholds,
        agency names, and legal or tax claims. Prefer sourced, current, and jurisdiction-specific evidence.
        INSTRUCTIONS),

        'validator_contradiction' => $role('VALIDATOR_CONTRADICTION', <<<'INSTRUCTIONS'
        Compare guidance, source summaries, and user context for contradictions. Flag conflicts between
        sources, internal inconsistencies, outdated claims, and advice that overstates certainty.
        INSTRUCTIONS),

        'validator_user_fit' => $role('VALIDATOR_USER_FIT', <<<'INSTRUCTIONS'
        Decide whether guidance fits the user's business profile. Check jurisdiction, entity type, stage,
        employee status, contractor usage, sales tax exposure, and professional-review needs.
        INSTRUCTIONS),

        'final_judge' => $role('FINAL_JUDGE', <<<'INSTRUCTIONS'
        Combine validator findings into one conservative decision. Prefer caveats and professional-review
        flags over confident approval when source freshness, jurisdiction, or user fit is uncertain.
        INSTRUCTIONS),

        'advisor_answer' => $role('ADVISOR_ANSWER', <<<'INSTRUCTIONS'
        Answer as a practical small-business advisor. Use the provided business profile and cached knowledge,
        cite source freshness when available, include caveats, and never present legal, tax, or payroll guidance
        as a substitute for a qualified professional.
        INSTRUCTIONS),

        'brand_copy' => $role('BRAND_COPY', <<<'INSTRUCTIONS'
        Write useful brand copy for a real small business. Be specific, plainspoken, and commercially credible.
        Avoid generic hype, vague superlatives, and language that sounds machine-written.
        INSTRUCTIONS, humanVoice: true),

        'ad_copy' => $role('AD_COPY', <<<'INSTRUCTIONS'
        Write concise ad copy for a real small business. Lead with a concrete offer or customer problem, keep
        claims supportable, avoid inflated urgency, and make the next action clear.
        INSTRUCTIONS, humanVoice: true),
    ],
];
