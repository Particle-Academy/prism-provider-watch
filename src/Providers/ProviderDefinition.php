<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Providers;

use Prism\ProviderWatch\Sources\Source;

final readonly class ProviderDefinition
{
    /**
     * @param  list<Source>  $sources
     * @param  list<string>  $prismHints
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $sources,
        public array $prismHints = [],
        /**
         * What kind of thing this is, which decides how the report frames it.
         *
         * A provider drifting asks "what in prism do we change". A competitor
         * shipping asks "does this change what we should build". Same
         * machinery, genuinely different questions, so they cannot share one
         * heading and one follow-up line without one of them reading wrong.
         */
        public string $category = self::CATEGORY_PROVIDER,
        /**
         * Why this target is watched. Carried into the report so a reader who
         * did not add the target still knows what a change here would mean.
         */
        public string $why = '',
    ) {}

    public const CATEGORY_PROVIDER = 'provider';

    public const CATEGORY_COMPETITOR = 'competitor';

    public const CATEGORY_ECOSYSTEM = 'ecosystem';

    public const CATEGORY_UPSTREAM = 'upstream';
}
