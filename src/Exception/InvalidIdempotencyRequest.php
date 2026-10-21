<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Exception;

/** A client error with a public, payload-free error code. */
final class InvalidIdempotencyRequest extends \InvalidArgumentException
{
    public function __construct(public readonly string $errorCode, public readonly int $statusCode = 400)
    {
        parent::__construct($errorCode);
    }
}
