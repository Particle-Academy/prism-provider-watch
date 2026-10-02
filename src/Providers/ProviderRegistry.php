<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Providers;

use RuntimeException;

/**
 * Loads config/providers.php and materializes it into ProviderDefinitions with
 * live Source objects, honoring an optional allow-list of provider keys.
 */
final class ProviderRegistry
{
    /** @param list<ProviderDefinition> $definitions */
    private function __construct(private readonly array $definitions) {}

    /**
     * @param  list<string>  $only  restrict to these provider keys (empty = all)
     */
    public static function fromConfigFile(string $path, SourceFactory $factory, array $only = []): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("Provider config not found: {$path}");
        }

        /** @var array<string, array<string, mixed>> $config */
        $config = require $path;

        $definitions = [];
        foreach ($config as $key => $spec) {
            if ($only !== [] && ! in_array($key, $only, true)) {
                continue;
            }

            $sources = [];
            foreach (($spec['sources'] ?? []) as $descriptor) {
                $sources[] = $factory->make($descriptor);
            }

            $definitions[] = new ProviderDefinition(
                key: (string) $key,
                label: (string) ($spec['label'] ?? $key),
                sources: $sources,
                prismHints: array_values(array_map('strval', (array) ($spec['prism_hints'] ?? []))),
                category: (string) ($spec['category'] ?? ProviderDefinition::CATEGORY_PROVIDER),
                why: (string) ($spec['why'] ?? ''),
            );
        }

        return new self($definitions);
    }

    /**
     * A registry composed directly, for callers that already hold their
     * definitions.
     *
     * ADDED FOR TESTABILITY, and the gap it closes is not academic. Until this
     * existed the only way to get a registry was fromConfigFile(), which builds
     * LIVE network sources through the SourceFactory — so WatchRun, the class
     * that loads the baseline and decides what to persist, could not be
     * exercised without the network and had no tests at all. The defect that
     * prompted this (a failed poll overwriting a good baseline) lived in
     * exactly that untested seam.
     *
     * @param  list<ProviderDefinition>  $definitions
     */
    public static function of(array $definitions): self
    {
        return new self($definitions);
    }

    /** @return list<ProviderDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }
}
