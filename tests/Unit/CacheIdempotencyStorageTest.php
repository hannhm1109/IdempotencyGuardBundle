<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Tests\Unit;

use Hanane\IdempotencyGuardBundle\Model\IdempotencyRecord;
use Hanane\IdempotencyGuardBundle\Storage\CacheIdempotencyStorage;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

final class CacheIdempotencyStorageTest extends TestCase
{
    public function testExpirationBoundaryMakesTheKeyAvailableAgainWithoutSleeping(): void
    {
        $clock = new MockClock('2099-01-01T00:00:00Z');
        $pool = new ArrayAdapter();
        $storage = new CacheIdempotencyStorage($pool, $clock);
        $record = IdempotencyRecord::capture('test_key', hash('sha256', 'request'), new Response('created', 201), 60, $clock->now()->getTimestamp());
        $storage->save($record);
        self::assertSame(60, $record->expiresAt - $record->createdAt);
        self::assertSame('created', $storage->find('test_key')?->responseBody);

        $clock->sleep(59);
        self::assertNotNull($storage->find('test_key'));
        $clock->sleep(1);
        self::assertNull($storage->find('test_key'));
        self::assertFalse($pool->hasItem('test_key'));

        $replacement = IdempotencyRecord::capture('test_key', hash('sha256', 'new request'), new Response('new'), 60, $clock->now()->getTimestamp());
        $storage->save($replacement);
        self::assertSame('new', $storage->find('test_key')?->responseBody);
    }

    public function testThePoolReceivesTheAbsoluteExpiration(): void
    {
        $now = time();
        $record = IdempotencyRecord::capture('test_key', hash('sha256', 'request'), new Response(), 60, $now);
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with($record)->willReturnSelf();
        $item->expects(self::once())->method('expiresAt')->with(self::callback(static fn (\DateTimeInterface $value): bool => $value->getTimestamp() === $now + 60))->willReturnSelf();
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->with('test_key')->willReturn($item);
        $pool->expects(self::once())->method('save')->with($item)->willReturn(true);

        (new CacheIdempotencyStorage($pool))->save($record);
    }

    public function testFalseSaveResultIsAnError(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn((new ArrayAdapter())->getItem('test_key'));
        $pool->method('save')->willReturn(false);
        $record = IdempotencyRecord::capture('test_key', hash('sha256', 'request'), new Response(), 60, time());

        $this->expectException(\RuntimeException::class);
        (new CacheIdempotencyStorage($pool))->save($record);
    }

    public function testFalseDeleteResultIsAnError(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('deleteItem')->with('test_key')->willReturn(false);
        $this->expectException(\RuntimeException::class);
        (new CacheIdempotencyStorage($pool))->delete('test_key');
    }

    public function testCorruptCacheValueDoesNotBecomeAMiss(): void
    {
        $pool = new ArrayAdapter();
        $pool->save($pool->getItem('test_key')->set(['private-data']));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid idempotency record in cache.');
        (new CacheIdempotencyStorage($pool))->find('test_key');
    }

    public function testMismatchedRecordKeyDoesNotReplayAnotherRecord(): void
    {
        $pool = new ArrayAdapter();
        $record = IdempotencyRecord::capture('other_key', hash('sha256', 'request'), new Response(), 60, time());
        $pool->save($pool->getItem('test_key')->set($record));
        $this->expectException(\RuntimeException::class);
        (new CacheIdempotencyStorage($pool))->find('test_key');
    }

    public function testMissingAndDeletedKeysAreAbsent(): void
    {
        $storage = new CacheIdempotencyStorage(new ArrayAdapter());
        self::assertNull($storage->find('test_key'));
        $storage->save(IdempotencyRecord::capture('test_key', hash('sha256', 'request'), new Response(), 60, time()));
        $storage->delete('test_key');
        self::assertNull($storage->find('test_key'));
    }
}
