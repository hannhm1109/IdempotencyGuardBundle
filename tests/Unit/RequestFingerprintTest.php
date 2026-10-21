<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Tests\Unit;

use Hanane\IdempotencyGuardBundle\Fingerprint\RequestFingerprint;
use Hanane\IdempotencyGuardBundle\Exception\InvalidIdempotencyRequest;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('equivalentJson')]
    public function testEquivalentJsonFingerprintsMatch(string $first, string $second): void
    {
        self::assertSame($this->jsonFingerprint($first), $this->jsonFingerprint($second));
    }

    public static function equivalentJson(): iterable
    {
        yield 'object ordering and whitespace' => ['{"amount":100,"currency":"MAD"}', "{\n\"currency\": \"MAD\", \"amount\": 100 }"];
        yield 'nested objects in arrays' => ['{"items":[{"a":1,"b":{"c":2,"d":3}}]}', '{"items":[{"b":{"d":3,"c":2},"a":1}]}'];
        yield 'empty containers' => ['{"a":{},"b":[]}', '{ "b": [ ], "a": { } }'];
        yield 'escaped strings' => ['{"url":"a\\/b","name":"\\u0061"}', '{"name":"a","url":"a/b"}'];
        yield 'Unicode escape' => ['{"currency":"\\u0645"}', '{"currency":"م"}'];
        yield 'numeric-looking object keys' => ['{"10":"a","2":"b"}', '{"2":"b","10":"a"}'];
        yield 'precision preserved with reordered keys' => ['{"n":0.123456789012345678901,"id":9223372036854775808}', '{"id":9223372036854775808,"n":0.123456789012345678901}'];
        yield 'scalars' => [' true ', 'true'];
        yield 'null' => ["\nnull", 'null'];
        yield 'escaped delimiters' => [json_encode(['x' => '"{}[],:\\end']), json_encode(['x' => '"{}[],:\\end'], JSON_PRETTY_PRINT)];
    }

    #[DataProvider('differentJson')]
    public function testMeaningfulOrConservativeJsonDifferencesConflict(string $first, string $second): void
    {
        self::assertNotSame($this->jsonFingerprint($first), $this->jsonFingerprint($second));
    }

    public static function differentJson(): iterable
    {
        yield 'array order' => ['[1,2]', '[2,1]'];
        yield 'empty object versus array' => ['{}', '[]'];
        yield 'numeric object versus array' => ['{"0":"a"}', '["a"]'];
        yield 'number versus string' => ['{"n":100}', '{"n":"100"}'];
        yield 'large number versus string' => ['9223372036854775808', '"9223372036854775808"'];
        yield 'adjacent huge integers' => ['9223372036854775808', '9223372036854775809'];
        yield 'adjacent precise decimals' => ['0.123456789012345678901', '0.123456789012345678902'];
        yield 'literal true versus string' => ['true', '"true"'];
        yield 'null versus empty' => ['null', ''];
        yield 'negative zero' => ['-0', '0'];
        yield 'integer versus fraction spelling' => ['1', '1.0'];
        yield 'exponent spelling' => ['1e2', '100'];
        yield 'no Unicode normalization' => ['"\\u00e9"', '"e\\u0301"'];
    }

    #[DataProvider('invalidJson')]
    public function testAmbiguousOrInvalidJsonIsRejected(string $body): void
    {
        $this->expectException(InvalidIdempotencyRequest::class);
        $this->expectExceptionMessage('IDEMPOTENCY_JSON_INVALID');
        $this->jsonFingerprint($body);
    }

    public static function invalidJson(): iterable
    {
        yield 'incomplete' => ['{"amount":'];
        yield 'trailing comma' => ['{"amount":1,}'];
        yield 'duplicate members' => ['{"amount":1,"amount":2}'];
        yield 'escaped duplicate member' => ['{"a":1,"\\u0061":2}'];
        yield 'nested duplicate member' => ['{"items":[{"x":1,"x":2}]}'];
        yield 'extra document' => ['{}{}'];
        yield 'whitespace only' => ['  '];
        yield 'invalid UTF-8' => ["{\"x\":\"\xFF\"}"];
        yield 'unpaired surrogate' => ['"\\ud800"'];
        yield 'too deep' => [str_repeat('[', 65).'0'.str_repeat(']', 65)];
    }

    public function testVendorJsonAndRawNonJsonBodiesUseTheirDocumentedPolicies(): void
    {
        self::assertSame($this->jsonFingerprint('{"b":2,"a":1}', 'application/vnd.orders+json; charset=UTF-8'), $this->jsonFingerprint('{"a":1,"b":2}', 'application/vnd.orders+json; charset=UTF-8'));
        self::assertNotSame($this->jsonFingerprint('{"b":2,"a":1}', 'text/plain'), $this->jsonFingerprint('{"a":1,"b":2}', 'text/plain'));
    }

    #[DataProvider('semanticHeaders')]
    public function testRepresentationAndPreconditionHeadersAffectFingerprint(string $name): void
    {
        $request = Request::create('/orders', 'POST', content: 'body');
        $fingerprint = new RequestFingerprint();
        $original = $fingerprint->generate($request);
        $request->headers->set($name, 'different');
        self::assertNotSame($original, $fingerprint->generate($request));
    }

    public static function semanticHeaders(): iterable
    {
        foreach (['Accept', 'Accept-Language', 'Accept-Encoding', 'If-Match', 'If-None-Match', 'If-Modified-Since', 'If-Unmodified-Since'] as $name) {
            yield $name => [$name];
        }
    }

    private function jsonFingerprint(string $body, string $type = 'application/json'): string
    {
        return (new RequestFingerprint())->generate(Request::create('/orders', 'POST', server: ['CONTENT_TYPE' => $type], content: $body));
    }
}
