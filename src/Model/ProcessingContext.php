<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Model;

use Symfony\Component\Lock\LockInterface;

/** @internal Request-local ownership of the lock and the pending response. */
final class ProcessingContext
{
    public function __construct(
        public readonly string $key,
        public readonly string $fingerprint,
        public readonly int $ttl,
        public readonly LockInterface $lock,
    ) {
    }
}
