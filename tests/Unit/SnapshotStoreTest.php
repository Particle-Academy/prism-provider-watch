<?php

declare(strict_types=1);

use Prism\ProviderWatch\Snapshot\Snapshot;
use Prism\ProviderWatch\Snapshot\SnapshotStore;
use Prism\ProviderWatch\Sources\SourceResult;

function tempStateDir(): string
{
    $dir = sys_get_temp_dir().'/prism-watch-test-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);

    return $dir;
}

it('returns null for a provider with no committed baseline', function () {
    $store = new SnapshotStore(tempStateDir());

    expect($store->load('openai'))->toBeNull();
});

it('round-trips a snapshot through disk', function () {
    $store = new SnapshotStore(tempStateDir());
    $snapshot = new Snapshot('openai', [
        'models' => SourceResult::ok('models', ['count' => 2, 'models' => ['a', 'b']]),
    ]);

    $store->save($snapshot);
    $loaded = $store->load('openai');

    expect($loaded)->not->toBeNull()
        ->and($loaded->provider)->toBe('openai')
        ->and($loaded->source('models')?->data['models'])->toBe(['a', 'b']);
});

it('writes pretty, newline-terminated JSON for reviewable git diffs', function () {
    $dir = tempStateDir();
    $store = new SnapshotStore($dir);
    $store->save(new Snapshot('openai', ['models' => SourceResult::ok('models', ['models' => ['a']])]));

    $json = (string) file_get_contents($store->pathFor('openai'));

    expect($json)->toContain("\n")
        ->and(str_ends_with($json, "\n"))->toBeTrue();
});
