<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Fingerprint;

use Symfony\Component\HttpFoundation\Request;

/** Phase 1 uses exact body/query bytes; JSON normalization is planned for Phase 2. */
final class RequestFingerprint
{
    public function generate(Request $request): string
    {
        $hash = hash_init('sha256');

        foreach ([
            'idempotency-guard-fingerprint-v1',
            $request->getMethod(),
            $request->getSchemeAndHttpHost(),
            $request->getBaseUrl().$request->getPathInfo(),
            (string) $request->server->get('QUERY_STRING', ''),
            $request->headers->get('Content-Type', ''),
            $request->getContent(),
        ] as $part) {
            // Length prefixes keep different field boundaries unambiguous.
            hash_update($hash, strlen($part).':'.$part);
        }

        return hash_final($hash);
    }
}
