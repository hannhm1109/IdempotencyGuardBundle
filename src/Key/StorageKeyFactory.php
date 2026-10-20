<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Key;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** Scopes a client key to its origin and authenticated principal without persisting credentials. */
final class StorageKeyFactory
{
    public function __construct(private readonly ?TokenStorageInterface $tokenStorage = null)
    {
    }

    public function create(Request $request, string $clientKey): string
    {
        $token = $this->tokenStorage?->getToken();
        $user = $token?->getUser();
        $firewall = $token !== null && method_exists($token, 'getFirewallName') ? $token->getFirewallName() : '';
        $scope = $user === null
            ? ['anonymous']
            : ['user', $firewall, $user::class, $user->getUserIdentifier()];

        return 'ig_'.hash('sha256', serialize([
            'v1',
            $request->getSchemeAndHttpHost(),
            $request->getBaseUrl(),
            $scope,
            $clientKey,
        ]));
    }
}
