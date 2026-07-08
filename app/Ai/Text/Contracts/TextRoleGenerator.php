<?php

namespace App\Ai\Text\Contracts;

use App\Ai\Text\TextGenerationRequest;
use App\Ai\Text\TextGenerationResult;

interface TextRoleGenerator
{
    public function generate(TextGenerationRequest $request): TextGenerationResult;
}
