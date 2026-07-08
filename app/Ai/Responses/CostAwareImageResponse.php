<?php

namespace App\Ai\Responses;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

class CostAwareImageResponse extends ImageResponse
{
    /**
     * @param  float|null  $costUsd  The actual USD amount the provider billed for this generation.
     */
    public function __construct(
        Collection $images,
        Usage $usage,
        Meta $meta,
        public ?float $costUsd = null,
    ) {
        parent::__construct($images, $usage, $meta);
    }
}
