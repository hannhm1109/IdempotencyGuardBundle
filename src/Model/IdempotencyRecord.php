<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Model;

use Symfony\Component\HttpFoundation\Response;

/** A completed response snapshot; the lock represents in-flight work. */
final class IdempotencyRecord
{
    public const MAX_BODY_BYTES = 1048576;
    private const REPLAY_HEADERS = ['content-type', 'location'];

    /** @param array<string, list<string>> $responseHeaders */
    public function __construct(
        public readonly string $key,
        public readonly string $fingerprint,
        public readonly int $responseStatusCode,
        public readonly string $responseBody,
        public readonly array $responseHeaders,
        public readonly int $createdAt,
        public readonly int $expiresAt,
    ) {
    }

    public static function capture(string $key, string $fingerprint, Response $response, int $ttl, int $now): self
    {
        if ($ttl < 1 || $ttl > PHP_INT_MAX - $now) {
            throw new \InvalidArgumentException('Invalid idempotency response TTL.');
        }
        if (strlen((string) $response->getContent()) > self::MAX_BODY_BYTES) {
            throw new \RuntimeException('The idempotency response exceeds the snapshot limit.');
        }

        $headers = [];
        foreach (self::REPLAY_HEADERS as $name) {
            $values = array_values(array_filter($response->headers->all($name), is_string(...)));
            if ($values !== []) {
                $headers[$name] = $values;
            }
        }

        return new self(
            $key,
            $fingerprint,
            $response->getStatusCode(),
            (string) $response->getContent(),
            $headers,
            $now,
            $now + $ttl,
        );
    }

    public function toResponse(): Response
    {
        // Apply the allowlist again at the adapter boundary, including custom adapters.
        $headers = [];
        foreach ($this->responseHeaders as $name => $values) {
            if (in_array(strtolower($name), self::REPLAY_HEADERS, true)) {
                $headers[$name] = $values;
            }
        }

        return new Response($this->responseBody, $this->responseStatusCode, $headers);
    }
}
