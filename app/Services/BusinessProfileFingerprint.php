<?php

namespace App\Services;

use RuntimeException;

final class BusinessProfileFingerprint
{
    /** @param array<array-key, mixed> $payload */
    public function make(array $payload): string
    {
        $configuredKey = config('security.profile_fingerprint_key');
        $key = is_string($configuredKey) && trim($configuredKey) !== ''
            ? $configuredKey
            : (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('A profile fingerprint key is required.');
        }

        return hash_hmac('sha256', $this->canonicalJson($payload), $key);
    }

    /** @param array<array-key, mixed> $payload */
    public function canonicalJson(array $payload): string
    {
        return json_encode($this->sort($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sort($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->sort($item), $value);
    }
}
