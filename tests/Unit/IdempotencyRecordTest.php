<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Tests\Unit;

use Hanane\IdempotencyGuardBundle\Model\IdempotencyRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyRecordTest extends TestCase
{
    public function testResponseSnapshotKeepsOnlyTheAllowlist(): void
    {
        $response = new Response('{"id":1}', 201, [
            'Content-Type' => 'application/json',
            'Location' => '/orders/1',
            'Authorization' => 'secret',
            'X-Api-Key' => 'secret',
            'Content-Length' => '8',
            'Connection' => 'close',
            'Transfer-Encoding' => 'chunked',
            'WWW-Authenticate' => 'Basic',
        ]);
        $response->headers->setCookie(Cookie::create('session', 'secret'));
        $record = IdempotencyRecord::capture('test_key', hash('sha256', 'request'), $response, 60, 100);
        self::assertSame(['content-type' => ['application/json'], 'location' => ['/orders/1']], $record->responseHeaders);
        self::assertSame(100, $record->createdAt);
        self::assertSame(160, $record->expiresAt);
        self::assertSame(201, $record->toResponse()->getStatusCode());
        self::assertSame('{"id":1}', $record->toResponse()->getContent());
        self::assertSame([], $record->toResponse()->headers->getCookies());
    }

    public function testCustomAdapterCannotInjectUnsafeReplayHeaders(): void
    {
        $record = new IdempotencyRecord('test_key', hash('sha256', 'request'), 201, '{}', [
            'Content-Type' => ['application/json'],
            'Set-Cookie' => ['secret=token'],
            'Authorization' => ['private-token'],
        ], 100, 160);
        $response = $record->toResponse();
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame([], $response->headers->getCookies());
        self::assertFalse($response->headers->has('Authorization'));
    }

    public function testTtlOverflowIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IdempotencyRecord::capture('test_key', hash('sha256', 'request'), new Response(), PHP_INT_MAX, time());
    }
}
