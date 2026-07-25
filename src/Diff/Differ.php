<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Diff;

use Prism\ProviderWatch\Snapshot\Snapshot;
use Prism\ProviderWatch\Sources\SourceResult;

/**
 * Compares the previous committed snapshot to the freshly polled one and emits
 * a flat list of {@see Change}s. Pure logic — no I/O — so it is fully unit
 * tested without touching the network or filesystem.
 *
 * First-observation rule: a source with no prior OK baseline (first ever run,
 * or previously skipped for a missing key) establishes a baseline SILENTLY.
 * Drift is only reported when BOTH sides have real data, so turning a key on
 * doesn't dump the entire model roster as "added".
 */
final class Differ
{
    /** @return list<Change> */
    public function diff(?Snapshot $old, Snapshot $new): array
    {
        $changes = [];

        foreach ($new->sources as $id => $fresh) {
            if (! $fresh->ok) {
                continue; // skipped/failed sources never drift
            }

            $prior = $old?->source($id);
            if ($prior === null || ! $prior->ok) {
                continue; // establish baseline silently
            }

            foreach ($this->diffSource($new->provider, $id, $prior, $fresh) as $change) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /** @return list<Change> */
    private function diffSource(string $provider, string $id, SourceResult $old, SourceResult $new): array
    {
        if (isset($new->data['models']) || isset($old->data['models'])) {
            return $this->diffModels($provider, $id, $old->data['models'] ?? [], $new->data['models'] ?? []);
        }

        if (isset($new->data['entries']) || isset($old->data['entries'])) {
            return $this->diffEntries($provider, $id, $old->data['entries'] ?? [], $new->data['entries'] ?? []);
        }

        // Unknown shape: report a single opaque change if the payload moved.
        if (json_encode($old->data) !== json_encode($new->data)) {
            return [new Change($provider, $id, ChangeType::Changed, 'payload', 'source payload changed')];
        }

        return [];
    }

    /**
     * @param  array<mixed>  $old
     * @param  array<mixed>  $new
     * @return list<Change>
     */
    private function diffModels(string $provider, string $id, array $old, array $new): array
    {
        $oldIds = array_map('strval', $old);
        $newIds = array_map('strval', $new);

        $changes = [];
        foreach (array_diff($newIds, $oldIds) as $added) {
            $changes[] = new Change($provider, $id, ChangeType::Added, $added, 'new model exposed by the provider');
        }
        foreach (array_diff($oldIds, $newIds) as $removed) {
            $changes[] = new Change($provider, $id, ChangeType::Removed, $removed, 'model no longer listed (possible deprecation)');
        }

        return $changes;
    }

    /**
     * @param  array<mixed>  $old
     * @param  array<mixed>  $new
     * @return list<Change>
     */
    private function diffEntries(string $provider, string $id, array $old, array $new): array
    {
        $oldIds = $this->entryIds($old);

        $changes = [];
        foreach ($new as $entry) {
            $entryId = is_array($entry) ? (string) ($entry['id'] ?? '') : '';
            if ($entryId === '' || in_array($entryId, $oldIds, true)) {
                continue;
            }
            $title = is_array($entry) ? (string) ($entry['title'] ?? $entryId) : $entryId;
            $changes[] = new Change($provider, $id, ChangeType::Added, $title, 'new release/commit/tag upstream');
        }

        return $changes;
    }

    /**
     * @param  array<mixed>  $entries
     * @return list<string>
     */
    private function entryIds(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry['id'])) {
                $ids[] = (string) $entry['id'];
            }
        }

        return $ids;
    }
}
