<?php

namespace App\Ai\Text;

class HumanVoiceGuidance
{
    public function marketingInstructions(): string
    {
        $path = resource_path('ai/text/avoid-ai-writing.md');

        if (is_file($path)) {
            return trim((string) file_get_contents($path));
        }

        return 'Write in a human voice: concrete claims, varied sentence length, plain words, no chatbot filler, no inflated buzzwords.';
    }
}
