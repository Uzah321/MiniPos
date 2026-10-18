<?php

namespace App\Services\Sync;

use JsonException;

class SyncPayloadHasher
{
    /**
     * Produce a stable SHA-256 hash for a JSON-compatible value.
     *
     * Object keys are sorted recursively while list order is preserved. This
     * lets an edge and the cloud compare the same payload independently of the
     * order in which PHP associative-array keys were assembled.
     */
    public function hash(mixed $payload): string
    {
        return hash('sha256', $this->encode($payload));
    }

    /** @throws JsonException */
    public function encode(mixed $payload): string
    {
        return json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
