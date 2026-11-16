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

    /**
     * The price fields this watcher records, in the order they are recorded.
     * Names are models.dev's own (`cost.input` etc.), USD per million tokens.
     *
     * models.dev publishes more under `cost` (reasoning, input_audio, tiers,
     * context_over_200k). Those are deliberately not recorded: these four are
     * what the Harness configures as its rates, so they are what a calibration
     * check compares against. Widening the set later is a first observation for
     * the new fields only if the differ is taught that too — see Differ.
     */
    public const PRICE_FIELDS = ['input', 'output', 'cache_read', 'cache_write'];

    /**
     * Per-model prices for a models.dev provider id, keyed by model id (sorted),
     * each with exactly the {@see PRICE_FIELDS} keys in a fixed order.
     *
     * A field models.dev does not publish is null, NEVER 0: "free" and "unknown"
     * are different facts, and a 0 here would make the first real price look
     * like a price change. Values are floats so `3` and `3.0` upstream cannot
     * produce two different snapshots of the same price.
     *
     * @return array<string, array<string, float|null>>|null
     */
    public function pricesFor(string $providerId): ?array
    {
        $this->ensureFetched();

        $models = $this->catalog[$providerId]['models'] ?? null;
        if (! is_array($models)) {
            return null;
        }

        $prices = [];
        foreach ($models as $id => $model) {
            $cost = is_array($model) && is_array($model['cost'] ?? null) ? $model['cost'] : [];
            $row = [];
            foreach (self::PRICE_FIELDS as $field) {
                $value = $cost[$field] ?? null;
                $row[$field] = is_int($value) || is_float($value) ? (float) $value : null;
            }
            $prices[(string) $id] = $row;
        }
        ksort($prices, SORT_STRING);

        return $prices;
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
