<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Storage;

use Hanane\IdempotencyGuardBundle\Model\IdempotencyRecord;

/** Implementations must surface storage failures and treat expired records as absent. */
interface IdempotencyStorageInterface
{
    public function find(string $key): ?IdempotencyRecord;

    public function save(IdempotencyRecord $record): void;

    public function delete(string $key): void;
}
