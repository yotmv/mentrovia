<?php

return [
    'model_id_pattern' => '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}\/[A-Za-z0-9][A-Za-z0-9._:-]{0,109}$/',
    'max_custom_models' => 12,

    'max_custom_models_by_purpose' => [
        'image' => (int) env('PHOTOSTUDIO_RESULTS_PER_BATCH', 3),
    ],

    'concurrency_retry_seconds' => (int) env('AI_CONCURRENCY_RETRY_SECONDS', 5),

    'default_estimated_cost_usd' => [
        'short_text' => 0.10,
        'long_text' => 0.50,
        'image_prompt' => 0.10,
        'image' => null,
        'auto' => 0.02,
    ],

    'model_estimated_cost_usd' => [
        'openrouter/auto' => 0.10,
        'openrouter/free' => 0.0,
        'google/gemini-2.5-flash-lite' => 0.02,
        'anthropic/claude-sonnet-4' => 0.50,
    ],

    'custom_image_max_reference_images' => 4,

    'trust_center' => [
        'audit_export_chunk_size' => 250,
    ],

    'auto_models' => [
        'short_text' => [
            'openrouter/auto',
            'google/gemini-2.5-flash-lite',
        ],
        'long_text' => [
            'openrouter/auto',
            'anthropic/claude-sonnet-4',
        ],
        'image_prompt' => [
            'openrouter/auto',
            'google/gemini-2.5-flash-lite',
        ],
        'image' => [
            'google/gemini-2.5-flash-image',
            'google/gemini-3.1-flash-image',
            'black-forest-labs/flux.2-pro',
            'openai/gpt-image-2',
        ],
        'auto' => [
            'openrouter/auto',
            'openrouter/free',
        ],
    ],

    'openrouter_preflight' => [
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'connect_timeout_seconds' => 3,
        'timeout_seconds' => 8,
        'attempts' => 3,
        'retry_delays_ms' => [100, 300],
        'max_response_bytes' => 2_000_000,
        'max_models' => 5_000,
        'label_max_length' => 100,
    ],
];
