<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Sources;

/**
 * A provider's model roster, sourced from the shared {@see ModelsDevCatalog}
 * (models.dev). Keyless and uniform across every provider — one catalog fetch
 * serves them all — so it replaces per-provider /models polling. The snapshot
 * shape ({count, models}) matches the endpoint source, so the differ is
 * unchanged.
 */
final class ModelsDevSource implements Source
{
    public function __construct(
        private readonly ModelsDevCatalog $catalog,
        private readonly string $id,
        private readonly string $label,
        private readonly string $providerId,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function poll(): SourceResult
    {
        if (! $this->catalog->available()) {
            return SourceResult::failed($this->id, 'models.dev unavailable: '.($this->catalog->error() ?? 'unknown error'));
        }

        $models = $this->catalog->modelsFor($this->providerId);
        if ($models === null) {
            return SourceResult::failed($this->id, "models.dev has no provider '{$this->providerId}'.");
        }

        return SourceResult::ok($this->id, [
            'count' => count($models),
            'models' => $models,
        ]);
    }
}
