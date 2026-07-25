<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Dispatch;

use Prism\ProviderWatch\Diff\DriftReport;

/**
 * A sink for a completed drift report. Dispatchers are additive and independent
 * — file, GitHub issue, (later) Tynn/knowledge — and each returns human-readable
 * log lines describing what it did. A dispatcher MUST NOT throw for an expected
 * failure (e.g. `gh` unauthenticated); return an explanatory note instead.
 */
interface Dispatcher
{
    /** @return list<string> log lines */
    public function dispatch(DriftReport $report): array;
}
