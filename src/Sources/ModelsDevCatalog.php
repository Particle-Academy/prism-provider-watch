<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Sources;

use Prism\ProviderWatch\Http\Http;

/**
 * The models.dev catalog — a single public, keyless JSON document listing every
 * model across ~180 providers (id, capabilities, context/output limits, cost,
 * release dates). Fetched ONCE per run and shared across every provider's
 * ModelsDevSource, so 10 providers cost one HTTP call, not ten. Fail-safe: a
 * failed fetch leaves the catalog null and every source reports it, rather than
 * throwing.
 */
final class ModelsDevCatalog
{
    /** @var array<string, mixed>|null */
    private ?array $catalog = null;

    private ?string $error = null;

    private bool $fetched = false;

    public function __construct(
        private readonly Http $http,
        private readonly string $url = 'https://models.dev/api.json',
    ) {}

    /**
     * Sorted, de-duplicated model ids for a models.dev provider id, or null if
     * the catalog is unavailable or has no such provider.
     *
     * @return list<string>|null
     */
    public function modelsFor(string $providerId): ?array
    {
        $this->ensureFetched();

        $models = $this->catalog[$providerId]['models'] ?? null;
        if (! is_array($models)) {
            return null;
        }

        $ids = array_map('strval', array_keys($models));
        sort($ids);

        return array_values(array_unique($ids));
    }

    public function available(): bool
    {
        $this->ensureFetched();

        return $this->catalog !== null;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    private function ensureFetched(): void
    {
        if ($this->fetched) {
            return;
        }
        $this->fetched = true;

        $response = $this->http->get($this->url, ['Accept' => 'application/json']);
        if (! $response->ok()) {
            $this->error = "GET {$this->url} returned HTTP {$response->status}.";

            return;
        }

        $data = $response->json();
        if (! is_array($data)) {
            $this->error = "GET {$this->url} returned non-JSON.";

            return;
        }

        $this->catalog = $data;
    }
}
