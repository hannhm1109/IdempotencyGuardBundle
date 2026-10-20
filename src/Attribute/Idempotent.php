<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Attribute;

/** Opts a controller method into idempotency protection. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Idempotent
{
    public function __construct(public readonly ?int $ttl = null)
    {
        if ($ttl !== null && $ttl < 1) {
            throw new \InvalidArgumentException('The idempotency TTL must be a positive number of seconds.');
        }
    }
}
