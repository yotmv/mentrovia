<?php

namespace App\Images;

final readonly class PhotoDerivativeResult
{
    /**
     * @param  array<string, array{path: string, width: int|null, height: int|null, size_bytes: int|null}>  $derivatives
     * @param  array<int, string>  $storedPaths
     */
    public function __construct(
        public array $derivatives,
        public array $storedPaths,
        public ?int $width,
        public ?int $height,
        public ?int $sizeBytes,
    ) {}
}
