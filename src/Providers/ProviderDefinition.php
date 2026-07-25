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
    ) {}
}
