<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Watch;

use Prism\ProviderWatch\Diff\Differ;
use Prism\ProviderWatch\Diff\DriftReport;
use Prism\ProviderWatch\Providers\ProviderRegistry;
use Prism\ProviderWatch\Snapshot\Snapshot;
use Prism\ProviderWatch\Snapshot\SnapshotStore;

/**
 * Orchestrates one full pass: poll every source of every registered provider,
 * snapshot it, diff against the committed baseline, persist the new baseline,
 * and fold all changes into one DriftReport. One dead source degrades to a
 * note; it never aborts the run.
 */
final class WatchRun
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly SnapshotStore $store,
        private readonly Differ $differ,
    ) {}

    public function run(string $generatedAt): WatchResult
    {
        $allChanges = [];
        $prismHints = [];
        $labels = [];
        $categories = [];
        $why = [];
        $notes = [];

        foreach ($this->registry->all() as $provider) {
            $sources = [];
            foreach ($provider->sources as $source) {
                $result = $source->poll();
                $sources[$source->id()] = $result;

                if (! $result->ok && $result->note !== '') {
                    $notes[] = "{$provider->key}/{$source->id()}: {$result->note}";
                }
            }

            $fresh = new Snapshot($provider->key, $sources);
            $previous = $this->store->load($provider->key);

            $changes = $this->differ->diff($previous, $fresh);
            if ($changes !== []) {
                $allChanges = array_merge($allChanges, $changes);
                $prismHints[$provider->key] = $provider->prismHints;
                $labels[$provider->key] = $provider->label;
                $categories[$provider->key] = $provider->category;
                $why[$provider->key] = $provider->why;
            }

            // Persist the new baseline so the next run diffs against today.
            $this->store->save($fresh);
        }

        return new WatchResult(
            new DriftReport($allChanges, $prismHints, $generatedAt, $labels, $categories, $why),
            $notes,
        );
    }
}
