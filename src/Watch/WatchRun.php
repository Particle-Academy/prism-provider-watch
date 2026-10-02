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
        $compared = 0;
        $uncompared = 0;

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

            foreach ($sources as $id => $result) {
                if (Differ::isComparable($previous?->source($id), $result)) {
                    $compared++;
                } else {
                    $uncompared++;
                }
            }

            $changes = $this->differ->diff($previous, $fresh);
            if ($changes !== []) {
                $allChanges = array_merge($allChanges, $changes);
                $prismHints[$provider->key] = $provider->prismHints;
                $labels[$provider->key] = $provider->label;
                $categories[$provider->key] = $provider->category;
                $why[$provider->key] = $provider->why;
            }

            // Persist the new baseline so the next run diffs against today --
            // but never let a failed poll overwrite a baseline that had real
            // data. A transient outage would otherwise erase the comparison
            // point, and the differ's first-observation rule would then adopt
            // the returning data SILENTLY: a day of genuine provider movement
            // swallowed, with both runs reporting no drift.
            $this->store->save($this->keepingUsableBaseline($previous, $fresh));
        }

        return new WatchResult(
            new DriftReport($allChanges, $prismHints, $generatedAt, $labels, $categories, $why, [], $compared, $uncompared),
            $notes,
        );
    }

    /**
     * The snapshot to commit: the fresh poll, except where a source came back
     * unusable and the baseline already held real data for it. There, the prior
     * result survives.
     *
     * The baseline's whole job is to be the last state someone understood. A
     * failed poll understands nothing, so writing it in loses the comparison
     * point and buys nothing.
     */
    private function keepingUsableBaseline(?Snapshot $previous, Snapshot $fresh): Snapshot
    {
        if ($previous === null) {
            return $fresh;
        }

        $sources = $fresh->sources;

        foreach ($sources as $id => $result) {
            $prior = $previous->source($id);

            if (! $result->ok && $prior !== null && $prior->ok) {
                $sources[$id] = $prior;
            }
        }

        return new Snapshot($fresh->provider, $sources);
    }
}
