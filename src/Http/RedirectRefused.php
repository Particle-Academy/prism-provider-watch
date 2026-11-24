<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Http;

use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * A redirect to a different origin, refused before it was followed. The
 * message names the status only: never the target, which comes from the
 * response and may carry anything.
 */
final class RedirectRefused extends RuntimeException implements GuzzleException
{
    public function __construct(int $status)
    {
        parent::__construct("HTTP {$status} redirect to a different origin was not followed.");
    }
}
