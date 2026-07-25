<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Sources;

/**
 * The canonical, normalized output of a single source poll for one provider.
 *
 * `data` is the diffable payload — it MUST be deterministic (sorted lists,
 * stable keys) so two captures of an unchanged upstream produce byte-identical
 * JSON and the differ reports no drift. Non-deterministic fields (timestamps,
 * request IDs) belong nowhere in here.
 */
final readonly class SourceResult
{
    /**
     * @param  array<string, mixed>  $data  deterministic, diffable payload
     */
    public function __construct(
        public string $id,
        public bool $ok,
        public array $data = [],
        public string $note = '',
    ) {}

    /** @param array<string, mixed> $data */
    public static function ok(string $id, array $data): self
    {
        return new self($id, true, $data);
    }

    /**
     * A non-fatal skip — e.g. a `/models` source with no API key configured.
     * Skipped sources never register as drift; they carry an actionable note.
     */
    public static function skipped(string $id, string $note): self
    {
        return new self($id, false, [], $note);
    }

    public static function failed(string $id, string $note): self
    {
        return new self($id, false, [], $note);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'note' => $this->note,
            'data' => $this->data,
        ];
    }
}
