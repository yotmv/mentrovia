<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Photo Storage
    |--------------------------------------------------------------------------
    |
    | Project photos live in the Mentrovia photo bucket under their
    | own key prefixes: user uploads are stored with the "uploaded_"
    | prefix and AI generated results with the "generated_" prefix.
    |
    */

    'disk' => env('PHOTOSTUDIO_IMAGE_DISK', 's3'),

    'uploaded_prefix' => env('PHOTOSTUDIO_UPLOADED_PREFIX', 'uploaded_'),

    'generated_prefix' => env('PHOTOSTUDIO_GENERATED_PREFIX', 'generated_'),

    'max_batch_inputs' => (int) env('PHOTOSTUDIO_MAX_BATCH_INPUTS', 12),

    'lifecycle' => [
        'queue_connection' => env('LIFECYCLE_QUEUE_CONNECTION', 'lifecycle-database'),
        'photo_queue' => env('LIFECYCLE_PHOTO_QUEUE', 'photo-lifecycle'),
        'security_queue' => env('LIFECYCLE_SECURITY_QUEUE', 'security-erasure'),
        'claim_seconds' => (int) env('LIFECYCLE_CLAIM_SECONDS', 600),
        'scheduler_heartbeat_name' => 'lifecycle-scheduler',
        'require_scheduler_heartbeat' => (bool) env(
            'LIFECYCLE_REQUIRE_SCHEDULER_HEARTBEAT',
            env('APP_ENV') === 'production',
        ),
        'scheduler_heartbeat_max_age' => (int) env('LIFECYCLE_SCHEDULER_HEARTBEAT_MAX_AGE', 180),
        'backlog_warning' => (int) env('LIFECYCLE_QUEUE_BACKLOG_WARNING', 1000),
        'oldest_job_warning_seconds' => (int) env('LIFECYCLE_OLDEST_JOB_WARNING_SECONDS', 900),
    ],

    'operation_lease_seconds' => (int) env('PHOTOSTUDIO_OPERATION_LEASE_SECONDS', 600),

    'account_erasure_retry_seconds' => (int) env('ACCOUNT_ERASURE_RETRY_SECONDS', 30),

    'account_erasure_chunk_size' => (int) env('ACCOUNT_ERASURE_CHUNK_SIZE', 50),

    'account_erasure_chunks_per_job' => (int) env('ACCOUNT_ERASURE_CHUNKS_PER_JOB', 50),

    'workspace_erasure_retry_seconds' => (int) env('WORKSPACE_ERASURE_RETRY_SECONDS', 30),

    'workspace_erasure_chunk_size' => (int) env('WORKSPACE_ERASURE_CHUNK_SIZE', 50),

    'workspace_erasure_chunks_per_job' => (int) env('WORKSPACE_ERASURE_CHUNKS_PER_JOB', 25),

    'workspace_erasure_dispatch_stale_seconds' => (int) env('WORKSPACE_ERASURE_DISPATCH_STALE_SECONDS', 600),

    'reconciliation' => [
        'limit' => (int) env('PHOTOSTUDIO_RECONCILIATION_LIMIT', 100),
        'warning_age_seconds' => (int) env('PHOTOSTUDIO_PENDING_WARNING_AGE_SECONDS', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generation Provider
    |--------------------------------------------------------------------------
    |
    | "auto" routes every batch through the best-value model chooser. Pin a
    | provider/model pair to bypass the chooser entirely as an escape
    | hatch (e.g. PHOTOSTUDIO_PROVIDER=openrouter + a model slug).
    |
    */

    'provider' => env('PHOTOSTUDIO_PROVIDER', 'auto'),

    'model' => env('PHOTOSTUDIO_MODEL'),

    'results_per_batch' => (int) env('PHOTOSTUDIO_RESULTS_PER_BATCH', 3),

    'http' => [
        'connect_timeout' => (int) env('PHOTOSTUDIO_HTTP_CONNECT_TIMEOUT', 10),
        'max_output_bytes' => (int) env('PHOTOSTUDIO_MAX_OUTPUT_BYTES', 26_214_400),
        'max_output_dimension' => (int) env('PHOTOSTUDIO_MAX_OUTPUT_DIMENSION', 8192),
        'max_output_pixels' => (int) env('PHOTOSTUDIO_MAX_OUTPUT_PIXELS', 40_000_000),
        'allowed_output_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'replicate_output_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('REPLICATE_OUTPUT_HOSTS', 'replicate.delivery')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Processing (Sharp)
    |--------------------------------------------------------------------------
    |
    | Every stored photo is normalized (EXIF rotation, sRGB, metadata
    | stripped) and rendered into web derivatives by a Node/Sharp worker
    | script. The LLM input stays lightly compressed so image models can
    | still read texture, seams, and edges; display derivatives compress
    | harder. Variants differ per photo kind: uploads only need an LLM
    | input plus grid sizes, generated masters get the full web set.
    |
    */

    'processing' => [
        'node_binary' => env('PHOTOSTUDIO_NODE_BINARY', 'node'),
        'script' => 'resources/js/image-processing/create-portfolio-derivatives.mjs',
        'timeout' => (int) env('PHOTOSTUDIO_PROCESSING_TIMEOUT', 120),

        'max_upload_mb' => (int) env('PHOTOSTUDIO_MAX_UPLOAD_MB', 25),
        'max_source_dimension' => (int) env('PHOTOSTUDIO_MAX_SOURCE_DIMENSION', 8000),

        'original_retention_days' => (int) env('PHOTOSTUDIO_ORIGINAL_RETENTION_DAYS', 30),

        'variants' => [
            'uploaded' => [
                'llm-input' => ['format' => 'jpeg', 'width' => 2048, 'height' => 2048, 'fit' => 'inside', 'quality' => 92],
                'card' => ['format' => 'webp', 'width' => 1200, 'height' => 1200, 'fit' => 'inside', 'quality' => 84],
                'thumb' => ['format' => 'webp', 'width' => 500, 'height' => 500, 'fit' => 'cover', 'quality' => 78],
            ],

            'generated' => [
                'master' => ['format' => 'webp', 'width' => 3200, 'height' => 3200, 'fit' => 'inside', 'quality' => 90],
                'hero' => ['format' => 'webp', 'width' => 2400, 'height' => 2400, 'fit' => 'inside', 'quality' => 88],
                'hero-jpg' => ['format' => 'jpeg', 'width' => 2400, 'height' => 2400, 'fit' => 'inside', 'quality' => 86],
                'card' => ['format' => 'webp', 'width' => 1200, 'height' => 1200, 'fit' => 'inside', 'quality' => 84],
                'thumb' => ['format' => 'webp', 'width' => 500, 'height' => 500, 'fit' => 'cover', 'quality' => 78],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vision Analysis
    |--------------------------------------------------------------------------
    |
    | A cheap multimodal model analyzes the uploaded photos before any
    | generation: it describes the subject, catalogs defects, decides
    | cleanup vs. recreate per image, and writes generation prompts.
    |
    */

    'analysis' => [
        'provider' => env('PHOTOSTUDIO_ANALYSIS_PROVIDER', 'openrouter'),
        'model' => env('PHOTOSTUDIO_ANALYSIS_MODEL', 'google/gemini-2.5-flash-lite'),
        'timeout' => (int) env('PHOTOSTUDIO_ANALYSIS_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Best-Value Model Chooser
    |--------------------------------------------------------------------------
    |
    | Candidates are hard-filtered by requirements, scored on curated
    | quality vs. cost vs. popularity, then a free LLM arbiter makes the
    | final ordered pick. Any arbiter failure silently falls back to
    | the heuristic ranking so generation never blocks on it.
    |
    */

    'chooser' => [
        'weights' => [
            'quality' => (float) env('AI_IMAGE_CHOOSER_QUALITY_WEIGHT', 0.55),
            'cost' => (float) env('AI_IMAGE_CHOOSER_COST_WEIGHT', 0.35),
            'popularity' => (float) env('AI_IMAGE_CHOOSER_POPULARITY_WEIGHT', 0.10),
        ],

        'requirements' => [
            'min_quality' => (int) env('AI_IMAGE_CHOOSER_MIN_QUALITY', 60),
            'max_usd_per_image' => (float) env('AI_IMAGE_CHOOSER_MAX_USD_PER_IMAGE', 0.10),
        ],

        'llm' => [
            'enabled' => (bool) env('AI_IMAGE_CHOOSER_LLM_ENABLED', true),
            'provider' => env('AI_IMAGE_CHOOSER_LLM_PROVIDER', 'openrouter'),
            'model' => env('AI_IMAGE_CHOOSER_LLM_MODEL', 'openrouter/free'),
            'top_candidates' => (int) env('AI_IMAGE_CHOOSER_LLM_TOP_CANDIDATES', 8),
            'cache_ttl_hours' => (int) env('AI_IMAGE_CHOOSER_CACHE_TTL_HOURS', 24),
            'timeout' => (int) env('AI_IMAGE_CHOOSER_TIMEOUT_SECONDS', 20),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | External (BYOK) Keys
    |--------------------------------------------------------------------------
    |
    | Some hosted models require your own key with the upstream vendor in
    | addition to the hosting provider's key. Profiles reference these
    | by name via their "external_key" option.
    |
    */

    'external_keys' => [
        'openai' => env('OPENAI_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Model Catalog
    |--------------------------------------------------------------------------
    |
    | The allowlist of usable image models. A model without a profile here
    | is rejected. Quality scores are curated judgment; prices are USD
    | per ~1MP image at the tier we request (July 2026). Refresh from
    | the provider discovery APIs periodically, not at request time.
    |
    | "edit_quality" rates instruction-following on edits of an existing
    | image separately from first-generation quality (it defaults to
    | "quality" when omitted). A score below the chooser's minimum
    | excludes the model from best-fit edit batches entirely.
    |
    */

    'models' => [

        'openrouter' => [

            'google/gemini-2.5-flash-image' => [
                'category' => 'speed_cost',
                'output' => 'raster',
                'quality' => 74,
                'usd_per_image' => 0.039,
                'popularity_rank' => 1,
                'recommended' => true,
                'supports_image_input' => true,
                'supports_editing' => true,
                'supports_text_rendering' => false,
                'supports_aspect_ratio' => true,
                'supports_quality' => false,
                'supports_output_format' => false,
                'max_reference_images' => 8,
            ],

            'google/gemini-3.1-flash-image' => [
                'category' => 'best_overall',
                'output' => 'raster',
                'quality' => 86,
                'usd_per_image' => 0.067,
                'popularity_rank' => 2,
                'recommended' => true,
                'supports_image_input' => true,
                'supports_editing' => true,
                'supports_text_rendering' => true,
                'supports_aspect_ratio' => true,
                'supports_quality' => false,
                'supports_output_format' => false,
                'max_reference_images' => 14,
            ],

            'bytedance-seed/seedream-4.5' => [
                'category' => 'photorealistic_cinematic',
                'output' => 'raster',
                'quality' => 84,
                'usd_per_image' => 0.04,
                'popularity_rank' => 4,
                'recommended' => false,
                'supports_image_input' => true,
                'supports_editing' => true,
                'supports_text_rendering' => false,
                'supports_aspect_ratio' => true,
                'supports_quality' => false,
                'supports_output_format' => false,
                'max_reference_images' => 10,
            ],

            'black-forest-labs/flux.2-pro' => [
                'category' => 'photorealistic_cinematic',
                'output' => 'raster',
                'quality' => 83,
                'usd_per_image' => 0.03,
                // FLUX.2 tokenizes reference images as input tokens (~$0.03
                // per ~2MP reference, observed from OpenRouter billing).
                'usd_per_reference_image' => 0.03,
                'popularity_rank' => 5,
                'recommended' => true,
                'supports_image_input' => true,
                'supports_editing' => true,
                'supports_text_rendering' => false,
                'supports_aspect_ratio' => false,
                'supports_quality' => false,
                'supports_output_format' => true,
                'max_reference_images' => 8,
            ],

            'openai/gpt-image-2' => [
                'category' => 'typography_design',
                'output' => 'raster',
                'quality' => 88,
                'usd_per_image' => 0.125,
                'usd_per_reference_image' => 0.015,
                'popularity_rank' => 3,
                'recommended' => true,
                'supports_image_input' => true,
                'supports_editing' => true,
                'supports_text_rendering' => true,
                'supports_aspect_ratio' => false,
                'supports_quality' => true,
                'supports_output_format' => false,
                'max_reference_images' => 16,
            ],

            'openai/gpt-image-1-mini' => [
                'category' => 'speed_cost',
                'output' => 'raster',
                'quality' => 72,
                // Token-billed via OpenAI's Images API: ~$0.011 per medium
                // ~1MP image ($8/M image-output tokens); reference images
                // bill as input tokens ($2.50/M, ~$0.005 per 2MP reference).
                'usd_per_image' => 0.011,
                'usd_per_reference_image' => 0.005,
                'popularity_rank' => null,
                'recommended' => false,
                'supports_image_input' => true,
                'supports_editing' => true,
                'supports_text_rendering' => false,
                'supports_aspect_ratio' => false,
                'supports_quality' => true,
                'supports_output_format' => false,
                'max_reference_images' => 16,
            ],

            'x-ai/grok-imagine-image-quality' => [
                'category' => 'photorealistic_cinematic',
                'output' => 'raster',
                'quality' => 85,
                'usd_per_image' => 0.05,
                'popularity_rank' => 9,
                'recommended' => false,
                'supports_image_input' => true,
                'supports_editing' => false,
                'supports_text_rendering' => false,
                'supports_aspect_ratio' => true,
                'supports_quality' => false,
                'supports_output_format' => false,
                'max_reference_images' => 4,
            ],

        ],

        'replicate' => [

            'black-forest-labs/flux-kontext-pro' => [
                'category' => 'best_overall',
                'output' => 'raster',
                'quality' => 82,
                // Strong first generations, but poor instruction-following
                // on second-pass edits (observed July 2026).
                'edit_quality' => 45,
                'usd_per_image' => 0.04,
                'popularity_rank' => null,
                'recommended' => true,
                'supports_image_input' => true,
                'supports_editing' => true,
                'supports_text_rendering' => false,
                'supports_aspect_ratio' => true,
                'supports_quality' => false,
                'supports_output_format' => true,
                'max_reference_images' => 1,
                'input_schema' => [
                    'image_input' => 'input_image',
                    'image_input_type' => 'single',
                ],
            ],

            'google/nano-banana' => [
                'category' => 'speed_cost',
                'output' => 'raster',
                'quality' => 74,
                'usd_per_image' => 0.039,
                'popularity_rank' => null,
                'recommended' => true,
                'supports_image_input' => true,
                'supports_editing' => true,
                'supports_text_rendering' => false,
                'supports_aspect_ratio' => true,
                'supports_quality' => false,
                'supports_output_format' => true,
                'max_reference_images' => 3,
                'input_schema' => [
                    'image_input' => 'image_input',
                    'image_input_type' => 'array',
                ],
            ],

            'bytedance/seedream-4' => [
                'category' => 'photorealistic_cinematic',
                'output' => 'raster',
                'quality' => 83,
                // Strong first generations, but poor instruction-following
                // on second-pass edits (observed July 2026).
                'edit_quality' => 40,
                'usd_per_image' => 0.03,
                'popularity_rank' => null,
                'recommended' => false,
                'supports_image_input' => true,
                'supports_editing' => true,
                'supports_text_rendering' => false,
                'supports_aspect_ratio' => true,
                'supports_quality' => false,
                'supports_output_format' => false,
                'max_reference_images' => 10,
                'input_schema' => [
                    'image_input' => 'image_input',
                    'image_input_type' => 'array',
                ],
            ],

        ],

        'stability' => [

            'core' => [
                'category' => 'speed_cost',
                'output' => 'raster',
                'quality' => 70,
                'usd_per_image' => 0.03,
                'popularity_rank' => null,
                'recommended' => false,
                'supports_image_input' => false,
                'supports_editing' => false,
                'supports_text_rendering' => false,
                'supports_aspect_ratio' => true,
                'supports_quality' => false,
                'supports_output_format' => true,
                'max_reference_images' => 0,
            ],

            'ultra' => [
                'category' => 'photorealistic_cinematic',
                'output' => 'raster',
                'quality' => 80,
                'usd_per_image' => 0.08,
                'popularity_rank' => null,
                'recommended' => false,
                'supports_image_input' => true,
                'supports_editing' => false,
                'supports_text_rendering' => false,
                'supports_aspect_ratio' => true,
                'supports_quality' => false,
                'supports_output_format' => true,
                'max_reference_images' => 1,
            ],

            'sd3' => [
                'category' => 'photorealistic_cinematic',
                'output' => 'raster',
                'quality' => 76,
                'usd_per_image' => 0.065,
                'popularity_rank' => null,
                'recommended' => false,
                'supports_image_input' => true,
                'supports_editing' => false,
                'supports_text_rendering' => false,
                'supports_aspect_ratio' => true,
                'supports_quality' => false,
                'supports_output_format' => true,
                'max_reference_images' => 1,
            ],

        ],

    ],

];
