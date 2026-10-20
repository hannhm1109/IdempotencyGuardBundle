<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Tests\Unit;

use Hanane\IdempotencyGuardBundle\Fingerprint\RequestFingerprint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestFingerprintTest extends TestCase
{
    public function testFingerprintIsDeterministicAndDoesNotIncludeCredentials(): void
    {
        $fingerprint = new RequestFingerprint();
        $request = Request::create('/orders?currency=MAD', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"amount":100}');
        $original = $fingerprint->generate($request);

        $request->headers->set('Authorization', 'Bearer private-token');
        $request->headers->set('Cookie', 'private-cookie');
        $request->headers->set('X-Request-Id', 'volatile');

        self::assertSame($original, $fingerprint->generate($request));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $original);
    }

    public function testMethodQueryAndBodyAffectFingerprint(): void
    {
        $fingerprint = new RequestFingerprint();
        $requests = [
            Request::create('/orders?currency=MAD', 'POST', content: '{"amount":100}'),
            Request::create('/orders?currency=EUR', 'POST', content: '{"amount":100}'),
            Request::create('/orders?currency=MAD', 'PATCH', content: '{"amount":100}'),
            Request::create('/orders?currency=MAD', 'POST', content: '{"amount":200}'),
        ];

        self::assertCount(4, array_unique(array_map($fingerprint->generate(...), $requests)));
    }
}
