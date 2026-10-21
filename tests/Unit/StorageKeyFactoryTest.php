<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Tests\Unit;

use Hanane\IdempotencyGuardBundle\Key\StorageKeyFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class StorageKeyFactoryTest extends TestCase
{
    public function testHashedKeysFitThePsr6PortableLimitAndDoNotContainClientInput(): void
    {
        $request = Request::create('https://example.test/orders', 'POST');
        $keys = new StorageKeyFactory();
        $key = $keys->create($request, 'customer-operation');
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $key);
        self::assertSame($key, $keys->create($request, 'customer-operation'));
        self::assertNotSame($key, $keys->create($request, 'another-operation'));
        self::assertStringNotContainsString('customer-operation', $key);
    }

    public function testPathsShareTheKeyScopeButDifferentOriginsDoNot(): void
    {
        $keys = new StorageKeyFactory();
        $original = $keys->create(Request::create('https://example.test/orders'), 'key');
        self::assertSame($original, $keys->create(Request::create('https://example.test/refunds'), 'key'));
        self::assertNotSame($original, $keys->create(Request::create('https://another.test/orders'), 'key'));
        self::assertNotSame($original, $keys->create(Request::create('http://example.test/orders'), 'key'));
    }

    public function testPrincipalsAndFirewallsAreSeparatedFromTheAnonymousScope(): void
    {
        $tokens = new TokenStorage();
        $keys = new StorageKeyFactory($tokens);
        $request = Request::create('/orders');
        $anonymous = $keys->create($request, 'key');
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));
        $alice = $keys->create($request, 'key');
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('bob', null), 'main'));
        $bob = $keys->create($request, 'key');
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'another'));
        self::assertCount(4, array_unique([$anonymous, $alice, $bob, $keys->create($request, 'key')]));
    }

    public function testCustomTokenWithoutFirewallMethodIsSupported(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new InMemoryUser('alice', null));
        $tokens = new TokenStorage();
        $tokens->setToken($token);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (new StorageKeyFactory($tokens))->create(Request::create('/orders'), 'key'));
    }
}
