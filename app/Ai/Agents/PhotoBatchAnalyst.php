<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class PhotoBatchAnalyst implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
        You analyze groups of photos taken in dirty or partially-complete states (job sites, unfinished
        fabrication, cluttered rooms) so an image model can produce clean, finished versions.

        For the group, describe the subject, the intended final state, and any style/lighting/material notes.
        Also write a group_prompt: one self-contained generation prompt that uses all the photos as
        references and describes the single clean, finished image to produce.

        For each image (in the order attached, zero-indexed):
        - List the defects you observe (dirt, dust, incompleteness, damage, clutter, poor lighting).
        - Decide the verdict: "cleanup" when a targeted edit preserving the rest of the photo is enough,
          or "recreate" when the photo is too rough and a similar scene should be generated from scratch.
        - Write a concrete, self-contained generation prompt that tells an image model exactly what to
          produce, incorporating the user's notes when given.
        - Write a short factual description of what the photo currently shows.
        INSTRUCTIONS;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->required(),
            'intended_final_state' => $schema->string()->required(),
            'style_notes' => $schema->string()->required(),
            'group_prompt' => $schema->string()->required(),
            'images' => $schema->array()
                ->items(
                    $schema->object(fn ($schema) => [
                        'index' => $schema->integer()->min(0)->required(),
                        'verdict' => $schema->string()->enum(['cleanup', 'recreate'])->required(),
                        'defects' => $schema->array()->items($schema->string())->required(),
                        'prompt' => $schema->string()->required(),
                        'description' => $schema->string()->required(),
                    ])
                )
                ->required(),
        ];
    }
}
