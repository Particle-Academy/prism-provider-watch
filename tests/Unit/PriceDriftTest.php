<?php

declare(strict_types=1);

use Prism\ProviderWatch\Diff\Change;
use Prism\ProviderWatch\Diff\ChangeType;
use Prism\ProviderWatch\Diff\Differ;
use Prism\ProviderWatch\Diff\DriftReport;
use Prism\ProviderWatch\Snapshot\Snapshot;
use Prism\ProviderWatch\Sources\SourceResult;

/*
 * G-291: price drift on the models-dev roster snapshot.
 */

/** @param array<string, array<string, float|int|null>> $prices */
function pricedSnapshot(array $prices): Snapshot
{
    $models = array_map('strval', array_keys($prices));
    sort($models);

    return new Snapshot('anthropic', [
        'models-dev' => SourceResult::ok('models-dev', ['count' => count($models), 'models' => $models, 'prices' => $prices]),
    ]);
}

/** @param list<string> $models */
function rosterOnlySnapshot(array $models): Snapshot
{
    return new Snapshot('anthropic', [
        'models-dev' => SourceResult::ok('models-dev', ['count' => count($models), 'models' => $models]),
    ]);
}

/** @return array<string, array<string, float|int|null>> */
function basePrices(): array
{
    return [
        'claude-haiku-4-5' => ['input' => 1.0, 'output' => 5.0, 'cache_read' => 0.1, 'cache_write' => 1.25],
        'claude-sonnet-4-5' => ['input' => 3.0, 'output' => 15.0, 'cache_read' => 0.3, 'cache_write' => null],
        'unpriced' => ['input' => null, 'output' => null, 'cache_read' => null, 'cache_write' => null],
    ];
}

/**
 * @param  list<Change>  $changes
 * @return list<string>
 */
function priceSubjects(array $changes): array
{
    return array_map(static fn (Change $c): string => $c->subject, $changes);
}

it('reports no price drift when nothing changed', function () {
    expect((new Differ)->diff(pricedSnapshot(basePrices()), pricedSnapshot(basePrices())))->toBe([]);
});

it('reports one input price change as exactly one Changed entry with before and after', function () {
    $new = basePrices();
    $new['claude-sonnet-4-5']['input'] = 2.5;

    $changes = (new Differ)->diff(pricedSnapshot(basePrices()), pricedSnapshot($new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->type)->toBe(ChangeType::Changed)
        ->and($changes[0]->source)->toBe('models-dev')
        ->and($changes[0]->subject)->toBe('claude-sonnet-4-5 price changed')
        ->and($changes[0]->detail)->toBe('input 3.00→2.50 /Mtok');
});

it('folds several moved fields of one model into one entry, in field order', function () {
    $new = basePrices();
    $new['claude-haiku-4-5']['cache_write'] = 1.5;
    $new['claude-haiku-4-5']['cache_read'] = 0.022;

    $changes = (new Differ)->diff(pricedSnapshot(basePrices()), pricedSnapshot($new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->detail)->toBe('cache_read 0.10→0.022 /Mtok; cache_write 1.25→1.50 /Mtok');
});

it('does not flood when prices are added to an existing roster-only baseline', function () {
    // The shape committed in state/ today: {count, models}, no prices key.
    $old = rosterOnlySnapshot(['claude-haiku-4-5', 'claude-sonnet-4-5', 'unpriced']);

    expect((new Differ)->diff($old, pricedSnapshot(basePrices())))->toBe([]);
});

it('still reports roster moves on the run that first records prices', function () {
    $changes = (new Differ)->diff(rosterOnlySnapshot(['claude-haiku-4-5']), pricedSnapshot(basePrices()));

    expect(priceSubjects($changes))->toEqualCanonicalizing(['claude-sonnet-4-5', 'unpriced']);
    foreach ($changes as $change) {
        expect($change->type)->toBe(ChangeType::Added);
    }
});

it('treats null to value as a first observation, not a change', function () {
    $new = basePrices();
    $new['unpriced']['input'] = 0.5;
    $new['claude-sonnet-4-5']['cache_write'] = 3.75;

    expect((new Differ)->diff(pricedSnapshot(basePrices()), pricedSnapshot($new)))->toBe([]);
});

it('keeps a null price null across a JSON round trip without reporting it', function () {
    $roundTrip = static fn (Snapshot $s): Snapshot => Snapshot::fromArray(
        json_decode((string) json_encode($s->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true),
    );

    $old = $roundTrip(pricedSnapshot(basePrices()));

    expect($old->sources['models-dev']->data['prices']['unpriced']['input'])->toBeNull()
        ->and($old->sources['models-dev']->data['prices']['claude-sonnet-4-5']['cache_write'])->toBeNull()
        ->and((new Differ)->diff($old, pricedSnapshot(basePrices())))->toBe([]);
});

it('does not mistake an integer price read back from JSON for a change', function () {
    // json_encode(3.0) without PRESERVE_ZERO_FRACTION writes 3, which reads back as int.
    $ints = basePrices();
    $ints['claude-sonnet-4-5']['input'] = 3;
    $ints['claude-sonnet-4-5']['output'] = 15;

    expect((new Differ)->diff(pricedSnapshot($ints), pricedSnapshot(basePrices())))->toBe([]);
});

it('reports a price that disappears', function () {
    $new = basePrices();
    $new['claude-haiku-4-5']['output'] = null;

    $changes = (new Differ)->diff(pricedSnapshot(basePrices()), pricedSnapshot($new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->type)->toBe(ChangeType::Changed)
        ->and($changes[0]->detail)->toBe('output 5.00→unpriced /Mtok');
});

it('does not double-report a removed model as a price change', function () {
    $new = basePrices();
    unset($new['claude-haiku-4-5']);

    $changes = (new Differ)->diff(pricedSnapshot(basePrices()), pricedSnapshot($new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->type)->toBe(ChangeType::Removed)
        ->and($changes[0]->subject)->toBe('claude-haiku-4-5');
});

it('orders price changes by model id, not by upstream order', function () {
    $new = array_reverse(basePrices(), true);
    $new['claude-sonnet-4-5']['output'] = 12.0;
    $new['claude-haiku-4-5']['output'] = 4.0;

    $changes = (new Differ)->diff(pricedSnapshot(basePrices()), pricedSnapshot($new));

    expect(priceSubjects($changes))->toBe(['claude-haiku-4-5 price changed', 'claude-sonnet-4-5 price changed']);
});

it('classifies a price change as catalogue drift, not contract', function () {
    $new = basePrices();
    $new['claude-sonnet-4-5']['input'] = 2.5;

    $report = new DriftReport((new Differ)->diff(pricedSnapshot(basePrices()), pricedSnapshot($new)));

    expect($report->notable())->toBe([])
        ->and($report->headline())->toBe('1 catalogue change(s), no contract changes');
});
