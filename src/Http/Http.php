<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Http;

/**
 * Thin HTTP contract so sources stay unit-testable with a fake and never
 * depend on Guzzle directly. Implementations must NOT throw on transport
 * errors or non-2xx — surface them as an {@see HttpResponse} (status 0 for
 * transport failure) so a single dead endpoint cannot abort a watch run.
 */
interface Http
{
    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): HttpResponse;
}
