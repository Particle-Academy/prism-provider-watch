<?php

declare(strict_types=1);

use Prism\ProviderWatch\Diff\Differ;
use Prism\ProviderWatch\Providers\ProviderDefinition;
use Prism\ProviderWatch\Providers\ProviderRegistry;
use Prism\ProviderWatch\Snapshot\SnapshotStore;
use Prism\ProviderWatch\Sources\Source;
use Prism\ProviderWatch\Sources\SourceResult;
use Prism\ProviderWatch\Watch\WatchRun;

/**
 * WatchRun had NO tests until this file, which is where the defect it now
 * guards against was living: the orchestrator persisted whatever came back from
 * the poll, so one failed fetch replaced a good baseline with the failure, and
 * the differ's first-observation rule then adopted the returning data silently.
 * A day of real provider movement could pass with both runs reporting no drift.
 *
 * The reason it was untested is itself the lesson: a registry could only be
 * built from a config file full of live network sources, so the seam was not
 * reachable from a unit test. See ProviderRegistry::of().
 */
final class FixedSource implements Source
{
    public function __construct(
        private readonly string $id,
        private readonly SourceResult $result,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->id;
    }

    public function poll(): SourceResult
    {
        return $this->result;
    }
}

function watchRunFor(string $dir, SourceResult ...$results): WatchRun
{
    $sources = [];
    foreach ($results as $result) {
        $sources[] = new FixedSource($result->id, $result);
    }

    return new WatchRun(
        ProviderRegistry::of([new ProviderDefinition(key: 'acme', label: 'Acme', sources: $sources)]),
        new SnapshotStore($dir),
        new Differ,
    );
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/watch-run-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0o777, true);
});

it('reports that nothing was compared when every source fails', function (): void {
    // No baseline on disk, so even an OK poll would be a first observation.
    $result = watchRunFor($this->dir, SourceResult::failed('feed', 'connect timeout'))->run('2026-10-02T00:00:00Z');

    expect($result->report->hasDrift())->toBeFalse()
        ->and($result->report->compared())->toBeFalse()
        ->and($result->report->comparedSources)->toBe(0)
        ->and($result->report->toMarkdown())->toContain('NOTHING WAS COMPARED')
        // The exact claim that used to be made on this path, and was false.
        ->and($result->report->toMarkdown())->not->toContain('match the committed baseline');
});

it('counts a source as compared only when both sides have real data', function (): void {
    $store = new SnapshotStore($this->dir);

    // First run: establishes the baseline, compares nothing.
    $first = watchRunFor($this->dir, SourceResult::ok('feed', ['entries' => [['id' => 'a', 'title' => 'A']]]))
        ->run('2026-10-02T00:00:00Z');

    expect($first->report->comparedSources)->toBe(0)
        ->and($first->report->compared())->toBeFalse();

    // Second run, same payload: now there are two real sides to compare.
    $second = watchRunFor($this->dir, SourceResult::ok('feed', ['entries' => [['id' => 'a', 'title' => 'A']]]))
        ->run('2026-10-02T00:00:01Z');

    expect($second->report->comparedSources)->toBe(1)
        ->and($second->report->hasDrift())->toBeFalse()
        ->and($second->report->toMarkdown())->toContain('no drift')
        ->and($store->load('acme')?->source('feed')?->ok)->toBeTrue();
});

it('does not let a failed poll overwrite a baseline that held real data', function (): void {
    $store = new SnapshotStore($this->dir);

    watchRunFor($this->dir, SourceResult::ok('feed', ['entries' => [['id' => 'a', 'title' => 'A']]]))
        ->run('2026-10-02T00:00:00Z');

    // The outage.
    $outage = watchRunFor($this->dir, SourceResult::failed('feed', 'connect timeout'))->run('2026-10-02T00:00:01Z');

    expect($outage->report->compared())->toBeFalse()
        // THE ASSERTION THAT MATTERS: the comparison point survived the outage.
        ->and($store->load('acme')?->source('feed')?->ok)->toBeTrue()
        ->and($store->load('acme')?->source('feed')?->data)->toBe(['entries' => [['id' => 'a', 'title' => 'A']]]);
});

it('reports the real change after an outage instead of adopting it silently', function (): void {
    // The whole point of preserving the baseline, end to end. Without it the
    // third run compares against the FAILURE, treats 'b' as a first
    // observation, and reports no drift -- losing it permanently.
    $payload = fn (array $ids): array => ['entries' => array_map(fn (string $id): array => ['id' => $id, 'title' => strtoupper($id)], $ids)];

    watchRunFor($this->dir, SourceResult::ok('feed', $payload(['a'])))->run('2026-10-02T00:00:00Z');
    watchRunFor($this->dir, SourceResult::failed('feed', 'connect timeout'))->run('2026-10-02T00:00:01Z');
    $recovered = watchRunFor($this->dir, SourceResult::ok('feed', $payload(['a', 'b'])))->run('2026-10-02T00:00:02Z');

    expect($recovered->report->hasDrift())->toBeTrue()
        ->and($recovered->report->comparedSources)->toBe(1)
        ->and(array_map(fn ($change): string => $change->subject, $recovered->report->changes))->toBe(['B']);
});

it('keeps a usable baseline per source rather than per provider', function (): void {
    $store = new SnapshotStore($this->dir);

    watchRunFor(
        $this->dir,
        SourceResult::ok('feed', ['entries' => [['id' => 'a', 'title' => 'A']]]),
        SourceResult::ok('roster', ['models' => ['m1']]),
    )->run('2026-10-02T00:00:00Z');

    // One source dies, the other keeps working. The dead one must retain its
    // baseline while the live one advances -- a provider-wide fallback would
    // either discard the new roster or resurrect the stale feed.
    $partial = watchRunFor(
        $this->dir,
        SourceResult::failed('feed', 'connect timeout'),
        SourceResult::ok('roster', ['models' => ['m1', 'm2']]),
    )->run('2026-10-02T00:00:01Z');

    expect($partial->report->comparedSources)->toBe(1)
        ->and($partial->report->uncomparedSources)->toBe(1)
        ->and($partial->report->compared())->toBeTrue()
        ->and($store->load('acme')?->source('feed')?->ok)->toBeTrue()
        ->and($store->load('acme')?->source('roster')?->data)->toBe(['models' => ['m1', 'm2']]);
});
