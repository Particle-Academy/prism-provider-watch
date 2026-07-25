<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Sources;

/**
 * A single watchable upstream signal for one provider.
 *
 * Implementations do the network I/O and normalize the response into a
 * deterministic {@see SourceResult}. They must NEVER throw for an expected
 * condition (missing key, 404, rate limit) — return SourceResult::skipped()
 * or ::failed() so one flaky source can't abort a whole watch run.
 */
interface Source
{
    /** Stable identifier, unique within a provider (e.g. "models", "openapi-releases"). */
    public function id(): string;

    /** Human-readable one-liner for reports. */
    public function label(): string;

    public function poll(): SourceResult;
}
