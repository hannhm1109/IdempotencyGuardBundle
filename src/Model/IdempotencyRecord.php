<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Model;

use Symfony\Component\HttpFoundation\Response;

/** A completed response snapshot; the lock represents in-flight work. */
final class IdempotencyRecord
{
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

    public static function capture(string $key, string $fingerprint, Response $response, int $ttl): self
    {
        $headers = [];
        foreach (['content-type', 'location'] as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = $response->headers->all($name);
            }
        }

        $now = time();

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
        return new Response($this->responseBody, $this->responseStatusCode, $this->responseHeaders);
    }
}
