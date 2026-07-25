<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Snapshot;

use RuntimeException;

/**
 * Reads/writes the committed provider snapshots. One pretty-printed, stable-key
 * JSON file per provider under the state dir; that file IS the tracked baseline
 * the differ compares against, so its diff in git equals "what upstream did".
 */
final class SnapshotStore
{
    public function __construct(private readonly string $stateDir) {}

    public function load(string $provider): ?Snapshot
    {
        $path = $this->pathFor($provider);
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        return Snapshot::fromArray($decoded);
    }

    public function save(Snapshot $snapshot): void
    {
        if (! is_dir($this->stateDir) && ! @mkdir($this->stateDir, 0o755, true) && ! is_dir($this->stateDir)) {
            throw new RuntimeException("Cannot create state dir: {$this->stateDir}");
        }

        // Trailing newline + pretty print keep git diffs minimal and reviewable.
        $json = json_encode(
            $snapshot->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        file_put_contents($this->pathFor($snapshot->provider), $json.PHP_EOL);
    }

    public function pathFor(string $provider): string
    {
        return rtrim($this->stateDir, '/\\').DIRECTORY_SEPARATOR.$provider.'.json';
    }
}
