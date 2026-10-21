<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Request;

use Hanane\IdempotencyGuardBundle\Exception\InvalidIdempotencyRequest;
use Symfony\Component\HttpFoundation\Request;

final class RequestValidator
{
    public const MAX_BODY_BYTES = 1048576;

    public function key(Request $request, string $headerName): string
    {
        $values = $request->headers->all($headerName);
        if ($values === []) {
            throw new InvalidIdempotencyRequest('IDEMPOTENCY_KEY_REQUIRED');
        }

        // Visible ASCII, excluding commas to reject coalesced duplicate headers.
        if (count($values) !== 1 || preg_match('/\A[\x21-\x2B\x2D-\x7E]{1,255}\z/D', $values[0]) !== 1) {
            throw new InvalidIdempotencyRequest('IDEMPOTENCY_KEY_INVALID');
        }

        $mediaType = strtolower(trim(explode(';', $request->headers->get('Content-Type', ''), 2)[0]));
        if ($request->files->count() > 0 || $request->request->count() > 0
            || str_starts_with($mediaType, 'multipart/') || $mediaType === 'application/x-www-form-urlencoded'
            || !in_array(strtolower(trim($request->headers->get('Content-Encoding', 'identity'))), ['', 'identity'], true)) {
            throw new InvalidIdempotencyRequest('IDEMPOTENCY_BODY_UNSUPPORTED');
        }

        // A partial/conditional response is not a snapshot of the full mutation result.
        if ($request->headers->has('Range') || $request->headers->has('If-Range')) {
            throw new InvalidIdempotencyRequest('IDEMPOTENCY_RANGE_UNSUPPORTED');
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            throw new InvalidIdempotencyRequest('IDEMPOTENCY_BODY_TOO_LARGE', 413);
        }

        return $values[0];
    }
}
