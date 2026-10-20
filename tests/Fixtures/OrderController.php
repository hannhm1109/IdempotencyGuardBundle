<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Tests\Fixtures;

use Hanane\IdempotencyGuardBundle\Attribute\Idempotent;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OrderController
{
    public int $executions = 0;

    #[Idempotent(ttl: 3600)]
    public function create(Request $request): JsonResponse
    {
        ++$this->executions;
        $response = new JsonResponse(['order' => $this->executions], 201, [
            'Location' => '/orders/'.$this->executions,
            'X-Request-Secret' => 'must-not-be-stored',
        ]);
        $response->headers->setCookie(Cookie::create('session', 'private-cookie'));

        return $response;
    }

    public function unprotected(): JsonResponse
    {
        return new JsonResponse(['execution' => ++$this->executions]);
    }

    #[Idempotent]
    public function failure(): Response
    {
        ++$this->executions;

        return new Response('Temporary failure', 500);
    }

    #[Idempotent]
    public function exception(): Response
    {
        ++$this->executions;

        throw new \RuntimeException('Controller failure');
    }

    #[Idempotent]
    #[IsGranted('CREATE_ORDER')]
    public function secured(Request $request): JsonResponse
    {
        return $this->create($request);
    }
}
