<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class IdempotencyGuardBundle extends Bundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
