<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Snapshot;

use Prism\ProviderWatch\Sources\SourceResult;

/**
 * The canonical current-state of one provider across all its sources.
 *
 * Serialization is deterministic (source keys sorted) so the committed
 * state/<provider>.json changes ONLY when upstream changed — making git
 * history itself the provider changelog.
 */
final readonly class Snapshot
{
    /** @param array<string, SourceResult> $sources keyed by source id */
    public function __construct(
        public string $provider,
        public array $sources,
    ) {}

    public function source(string $id): ?SourceResult
    {
        return $this->sources[$id] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $sources = [];
        foreach ($this->sources as $id => $result) {
            $sources[$id] = $result->toArray();
        }
        ksort($sources);

        return [
            'provider' => $this->provider,
            'sources' => $sources,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $sources = [];
        foreach (($data['sources'] ?? []) as $id => $raw) {
            $sources[(string) $id] = new SourceResult(
                id: (string) $id,
                ok: (bool) ($raw['ok'] ?? false),
                data: is_array($raw['data'] ?? null) ? $raw['data'] : [],
                note: (string) ($raw['note'] ?? ''),
            );
        }

        return new self((string) ($data['provider'] ?? ''), $sources);
    }
}
