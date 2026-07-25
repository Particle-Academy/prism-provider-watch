<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Watch;

use Prism\ProviderWatch\Diff\DriftReport;

final readonly class WatchResult
{
    /**
     * @param  list<string>  $notes  operational notes (skipped/failed sources), not drift
     */
    public function __construct(
        public DriftReport $report,
        public array $notes = [],
    ) {}
}
