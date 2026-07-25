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
            );
        }

        return new self($definitions);
    }

    /** @return list<ProviderDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }
}
