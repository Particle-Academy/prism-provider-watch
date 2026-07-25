<?php

declare(strict_types=1);

use Prism\ProviderWatch\Diff\ChangeType;
use Prism\ProviderWatch\Diff\Differ;
use Prism\ProviderWatch\Snapshot\Snapshot;
use Prism\ProviderWatch\Sources\SourceResult;

/** @param list<string> $models */
function modelsSnapshot(array $models): Snapshot
{
    return new Snapshot('openai', [
        'models' => SourceResult::ok('models', ['count' => count($models), 'models' => $models]),
    ]);
}

/** @param list<array{id: string, title: string}> $entries */
function atomSnapshot(array $entries): Snapshot
{
    return new Snapshot('openai', [
        'sdk-python' => SourceResult::ok('sdk-python', [
            'latest' => $entries[0]['title'] ?? '',
            'entries' => $entries,
        ]),
    ]);
}

/** @return list<string> */
function subjects(array $changes): array
{
    return array_map(static fn ($c) => $c->subject, $changes);
}

it('reports no drift on the first observation', function () {
    expect((new Differ)->diff(null, modelsSnapshot(['gpt-4.1', 'gpt-4o'])))->toBe([]);
});

it('detects added and removed models', function () {
    $changes = (new Differ)->diff(
        modelsSnapshot(['gpt-4.1', 'gpt-4o']),
        modelsSnapshot(['gpt-4.1', 'gpt-5', 'o3']),
    );

    $added = array_values(array_filter($changes, fn ($c) => $c->type === ChangeType::Added));
    $removed = array_values(array_filter($changes, fn ($c) => $c->type === ChangeType::Removed));

    expect(subjects($added))->toEqualCanonicalizing(['gpt-5', 'o3']);
    expect(subjects($removed))->toEqualCanonicalizing(['gpt-4o']);
});

it('detects a new release entry and ignores ones that scrolled off', function () {
    $changes = (new Differ)->diff(
        atomSnapshot([['id' => 'v1', 'title' => '1.0.0']]),
        atomSnapshot([['id' => 'v2', 'title' => '2.0.0'], ['id' => 'v1', 'title' => '1.0.0']]),
    );

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->subject)->toBe('2.0.0')
        ->and($changes[0]->type)->toBe(ChangeType::Added);
});

it('stays silent when a key turns on so the roster is not dumped as added', function () {
    $old = new Snapshot('openai', ['models' => SourceResult::skipped('models', 'no key')]);

    expect((new Differ)->diff($old, modelsSnapshot(['gpt-4.1', 'gpt-4o'])))->toBe([]);
});

it('ignores skipped or failed sources in the fresh snapshot', function () {
    $new = new Snapshot('openai', ['models' => SourceResult::failed('models', 'HTTP 500')]);

    expect((new Differ)->diff(modelsSnapshot(['gpt-4.1']), $new))->toBe([]);
});

it('produces no drift when nothing changed', function () {
    expect((new Differ)->diff(modelsSnapshot(['a', 'b']), modelsSnapshot(['a', 'b'])))->toBe([]);
});
