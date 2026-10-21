<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Fingerprint;

use Symfony\Component\HttpFoundation\Request;

/** Logical JSON comparison; other media types and query strings remain byte-exact. */
final class RequestFingerprint
{
    public function __construct(private readonly JsonBodyNormalizer $json = new JsonBodyNormalizer())
    {
    }

    public function generate(Request $request): string
    {
        $hash = hash_init('sha256');
        $contentType = $request->headers->get('Content-Type', '');
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        $body = $request->getContent();
        if ($body !== '' && preg_match('~\Aapplication/(?:[a-z0-9!#$&^_.+-]+\+)?json\z~', $mediaType) === 1) {
            $body = $this->json->normalize($body);
        }

        foreach ([
            'idempotency-guard-fingerprint-v2',
            $request->getMethod(),
            $request->getSchemeAndHttpHost(),
            $request->getBaseUrl().$request->getPathInfo(),
            (string) $request->server->get('QUERY_STRING', ''),
            $contentType,
            $request->headers->get('Accept', ''),
            $request->headers->get('Accept-Language', ''),
            $request->headers->get('Accept-Encoding', ''),
            $request->headers->get('If-Match', ''),
            $request->headers->get('If-None-Match', ''),
            $request->headers->get('If-Unmodified-Since', ''),
            $request->headers->get('If-Modified-Since', ''),
            $body,
        ] as $part) {
            // Length prefixes keep different field boundaries unambiguous.
            hash_update($hash, strlen($part).':'.$part);
        }

        return hash_final($hash);
    }
}
