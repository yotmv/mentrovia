<?php

namespace App\Exceptions;

use RuntimeException;

final class BusinessProfileConflictException extends RuntimeException
{
    /**
     * @param  array<string, array{current: bool|int|string|null, yours: bool|int|string|null}>  $conflicts
     * @param  array<string, bool|int|string|null>  $yourPatch
     */
    public function __construct(
        public readonly array $conflicts,
        public readonly array $yourPatch,
    ) {
        parent::__construct('The company profile changed in the fields you edited.');
    }
}
