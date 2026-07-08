<?php

namespace App\Ai\Images;

readonly class ImageRequirements
{
    public const string TASK_GENERATE = 'generate';

    public const string TASK_EDIT = 'edit';

    public function __construct(
        public string $output = 'raster',
        public ?string $aspectRatio = null,
        public bool $requiresImageInput = false,
        public bool $requiresEditing = false,
        public bool $requiresTextRendering = false,
        public int $minQuality = 0,
        public ?float $maxUsdPerImage = null,
        public int $referenceImageCount = 0,
        public string $task = self::TASK_GENERATE,
    ) {}

    /**
     * Build requirements for a photo cleanup / re-creation batch, using the
     * configured chooser thresholds.
     */
    public static function forPhotoBatch(): self
    {
        return new self(
            requiresImageInput: true,
            minQuality: (int) config('photostudio.chooser.requirements.min_quality', 60),
            maxUsdPerImage: (float) config('photostudio.chooser.requirements.max_usd_per_image', 0.10),
        );
    }

    /**
     * Whether the requested aspect ratio needs native model support. Square
     * is every model's default, so it is treated as universally supported.
     */
    public function needsAspectRatioSupport(): bool
    {
        return $this->aspectRatio !== null && $this->aspectRatio !== '1:1';
    }

    /**
     * A stable, cache-friendly array representation.
     *
     * @return array<string, mixed>
     */
    /**
     * Whether this is an instruction-following edit of an existing image
     * rather than a first generation. Edit tasks judge candidates on
     * their edit-specific quality score.
     */
    public function isEditTask(): bool
    {
        return $this->task === self::TASK_EDIT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task' => $this->task,
            'output' => $this->output,
            'aspect_ratio' => $this->aspectRatio,
            'image_input' => $this->requiresImageInput,
            'editing' => $this->requiresEditing,
            'text_rendering' => $this->requiresTextRendering,
            'min_quality' => $this->minQuality,
            'max_usd_per_image' => $this->maxUsdPerImage,
            'reference_image_count' => $this->referenceImageCount,
        ];
    }
}
