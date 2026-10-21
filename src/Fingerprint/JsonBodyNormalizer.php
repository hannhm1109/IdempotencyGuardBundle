<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Fingerprint;

use Hanane\IdempotencyGuardBundle\Exception\InvalidIdempotencyRequest;

/** Sorts validated JSON tokens without rounding numbers through PHP floats. */
final class JsonBodyNormalizer
{
    public function normalize(string $json): string
    {
        try {
            // Native decoding validates syntax, UTF-8, escapes, and bounded nesting.
            // Its resulting values are discarded: number tokens remain lossless below.
            json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new InvalidIdempotencyRequest('IDEMPOTENCY_JSON_INVALID');
        }

        $matched = preg_match_all('/"(?:[^"\\\\]++|\\\\.)*"|[{}\[\]:,]|[^\s{}\[\]:,]+/s', $json, $matches);
        if ($matched === false) {
            throw new InvalidIdempotencyRequest('IDEMPOTENCY_JSON_INVALID');
        }

        $offset = 0;

        return $this->value($matches[0], $offset);
    }

    /** @param list<string> $tokens Native validation guarantees the token structure. */
    private function value(array $tokens, int &$offset): string
    {
        $token = $tokens[$offset++];
        if ($token === '{') {
            $members = [];
            while ($tokens[$offset] !== '}') {
                $key = $this->string($tokens[$offset++]);
                ++$offset; // colon
                if (array_key_exists($key, $members)) {
                    // Including escaped aliases such as "a" and "\u0061".
                    throw new InvalidIdempotencyRequest('IDEMPOTENCY_JSON_INVALID');
                }
                $members[$key] = $this->value($tokens, $offset);
                if ($tokens[$offset] !== ',') {
                    break;
                }
                ++$offset;
            }
            ++$offset; // closing brace
            ksort($members, SORT_STRING);
            $parts = [];
            foreach ($members as $key => $value) {
                $parts[] = $key.':'.$value;
            }

            return '{'.implode(',', $parts).'}';
        }

        if ($token === '[') {
            $values = [];
            while ($tokens[$offset] !== ']') {
                $values[] = $this->value($tokens, $offset);
                if ($tokens[$offset] !== ',') {
                    break;
                }
                ++$offset;
            }
            ++$offset; // closing bracket

            return '['.implode(',', $values).']';
        }

        return str_starts_with($token, '"') ? $this->string($token) : $token;
    }

    private function string(string $token): string
    {
        return json_encode(json_decode($token, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
