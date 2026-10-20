<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Storage;

use Hanane\IdempotencyGuardBundle\Model\IdempotencyRecord;
use Psr\Cache\CacheItemPoolInterface;

final class CacheIdempotencyStorage implements IdempotencyStorageInterface
{
    public function __construct(private readonly CacheItemPoolInterface $pool)
    {
    }

    public function find(string $key): ?IdempotencyRecord
    {
        $item = $this->pool->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $record = $item->get();
        if (!$record instanceof IdempotencyRecord || $record->key !== $key) {
            throw new \RuntimeException('Invalid idempotency record in cache.');
        }

        if ($record->expiresAt <= time()) {
            $this->delete($key);

            return null;
        }

        return $record;
    }

    public function save(IdempotencyRecord $record): void
    {
        $item = $this->pool->getItem($record->key);
        $item->set($record);
        $item->expiresAt(new \DateTimeImmutable('@'.$record->expiresAt));

        if (!$this->pool->save($item)) {
            throw new \RuntimeException('Cannot save the idempotency response.');
        }
    }

    public function delete(string $key): void
    {
        if (!$this->pool->deleteItem($key)) {
            throw new \RuntimeException('Cannot delete the idempotency response.');
        }
    }
}
