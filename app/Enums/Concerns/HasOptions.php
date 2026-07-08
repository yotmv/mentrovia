<?php

namespace App\Enums\Concerns;

trait HasOptions
{
    /**
     * Get value => label pairs for select inputs.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
