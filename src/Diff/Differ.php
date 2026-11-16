<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Diff;

use Prism\ProviderWatch\Snapshot\Snapshot;
use Prism\ProviderWatch\Sources\ModelsDevCatalog;
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
            $prior = $old?->source($id);

            // The null check is spelled out rather than left to isComparable()
            // so the narrowing is real to a static analyser instead of asserted
            // in a docblock.
            if ($prior === null || ! self::isComparable($prior, $fresh)) {
                continue;
            }

            foreach ($this->diffSource($new->provider, $id, $prior, $fresh) as $change) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /**
     * Whether this source has real data on BOTH sides, so a comparison here
     * means something.
     *
     * It is public and static because the count of comparisons made is the
     * only thing separating "compared everything and upstream is unchanged"
     * from "compared nothing". If the caller reimplemented this predicate the
     * two could disagree, and the number reported to the operator would stop
     * describing the comparison it claims to describe.
     *
     * A false here is one of two harmless-looking things:
     *   - the fresh poll is skipped or failed, so there is nothing to compare;
     *   - there is no prior OK baseline, so this is a first observation and
     *     adopting it silently is correct (see the class docblock).
     *
     * Harmless per source. NOT harmless in aggregate: if this returns false for
     * every source, the differ emits no changes, and no changes is the same
     * value it emits when upstream genuinely has not moved.
     */
    public static function isComparable(?SourceResult $prior, SourceResult $fresh): bool
    {
        return $fresh->ok && $prior !== null && $prior->ok;
    }

    /** @return list<Change> */
    private function diffSource(string $provider, string $id, SourceResult $old, SourceResult $new): array
    {
        if (isset($new->data['models']) || isset($old->data['models'])) {
            $changes = $this->diffModels($provider, $id, $old->data['models'] ?? [], $new->data['models'] ?? []);

            // Prices ride on the roster snapshot (models-dev only). A baseline
            // written before prices were recorded has no `prices` key at all:
            // that is a first observation of the price fields and is adopted
            // silently, exactly as a whole source is — otherwise the first run
            // after this shipped would report every model's price as new.
            if (is_array($old->data['prices'] ?? null) && is_array($new->data['prices'] ?? null)) {
                foreach ($this->diffPrices($provider, $id, $old->data['prices'], $new->data['prices']) as $change) {
                    $changes[] = $change;
                }
            }

            return $changes;
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
     * One Changed entry per model whose recorded price moved, every moved field
     * in the detail ("input 3.00→2.50 /Mtok; output 15.00→12.50 /Mtok").
     *
     * Only models present on both sides are compared: a model that appeared or
     * vanished is already reported by the roster diff, and repeating it as a
     * price change would count one event twice.
     *
     * Per field:
     *   - null→value is a first observation (models.dev started publishing a
     *     price it did not have) and is adopted silently, like any baseline;
     *   - value→null IS reported ("input 3.00→unpriced /Mtok"): a price that
     *     disappears is one a calibration check can no longer verify, and
     *     staying silent would hide exactly that;
     *   - value→value is compared numerically, so `3` vs `3.0` in a baseline
     *     read back from JSON is not drift.
     *
     * The source id is unchanged (`models-dev`), so these classify as catalogue
     * drift in DriftReport::notable() and in the Lab, not contract drift.
     *
     * @param  array<mixed>  $old
     * @param  array<mixed>  $new
     * @return list<Change>
     */
    private function diffPrices(string $provider, string $id, array $old, array $new): array
    {
        $models = array_map('strval', array_keys($new));
        sort($models, SORT_STRING);

        $changes = [];
        foreach ($models as $model) {
            $before = $old[$model] ?? null;
            $after = $new[$model] ?? null;
            if (! is_array($before) || ! is_array($after)) {
                continue;
            }

            $moved = [];
            foreach (ModelsDevCatalog::PRICE_FIELDS as $field) {
                $was = self::price($before[$field] ?? null);
                $now = self::price($after[$field] ?? null);
                if ($was === null || $was === $now) {
                    continue;
                }
                $moved[] = sprintf('%s %s→%s /Mtok', $field, self::formatPrice($was), $now === null ? 'unpriced' : self::formatPrice($now));
            }

            if ($moved !== []) {
                $changes[] = new Change($provider, $id, ChangeType::Changed, "{$model} price changed", implode('; ', $moved));
            }
        }

        return $changes;
    }

    private static function price(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /** At least two decimals, more only when the price needs them: 3.00, 0.10, 0.022. */
    private static function formatPrice(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        $dot = strpos($text, '.');
        $decimals = $dot === false ? 0 : strlen($text) - $dot - 1;

        return $decimals >= 2 ? $text : number_format($value, 2, '.', '');
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
