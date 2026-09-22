<?php

declare(strict_types=1);

use Prism\ProviderWatch\Diff\Change;
use Prism\ProviderWatch\Diff\ChangeType;
use Prism\ProviderWatch\Diff\DriftReport;
use Prism\ProviderWatch\Dispatch\GitHubIssueDispatcher;
use Prism\ProviderWatch\GitHub\GitHubCli;

/** In-memory GitHubCli that records calls instead of shelling out. */
function fakeCli(bool $available = true, ?int $existing = null): GitHubCli
{
    return new class($available, $existing) implements GitHubCli
    {
        public int $created = 0;

        public function __construct(private bool $isAvailable, private ?int $existing) {}

        public function available(): bool
        {
            return $this->isAvailable;
        }

        public function findOpenIssueByTitle(string $repo, string $title): ?int
        {
            return $this->existing;
        }

        public function createIssue(string $repo, string $title, string $body, array $labels = []): string
        {
            $this->created++;

            return 'https://github.com/particle-academy/prism/issues/123';
        }
    };
}

/**
 * A day worth filing: the CONTRACT moved.
 *
 * The source matters. Only an `openapi-spec` change opens an issue now, so a
 * fixture built from a catalogue source would make every test below assert the
 * dispatcher's silence rather than the behaviour it is named for.
 */
function driftReport(): DriftReport
{
    return new DriftReport([new Change('openai', 'openapi-spec', ChangeType::Changed, 'POST /v1/responses')], [], '2026-07-25T00:00:00Z');
}

it('does nothing when there is no drift', function () {
    $cli = fakeCli();
    $dispatcher = new GitHubIssueDispatcher($cli, 'particle-academy/prism', enabled: true);

    expect($dispatcher->dispatch(new DriftReport([], [], '2026-07-25T00:00:00Z')))->toBe([])
        ->and($cli->created)->toBe(0);
});

it('reports disabled and never creates an issue when not enabled', function () {
    $cli = fakeCli();
    $dispatcher = new GitHubIssueDispatcher($cli, 'particle-academy/prism', enabled: false);

    $lines = $dispatcher->dispatch(driftReport());

    expect($lines[0])->toContain('disabled')
        ->and($cli->created)->toBe(0);
});

it('opens an issue when enabled and none exists yet', function () {
    $cli = fakeCli(existing: null);
    $dispatcher = new GitHubIssueDispatcher($cli, 'particle-academy/prism', enabled: true);

    $lines = $dispatcher->dispatch(driftReport());

    expect($lines[0])->toContain('opened')
        ->and($cli->created)->toBe(1);
});

it('is idempotent — skips creation when an open issue already exists', function () {
    $cli = fakeCli(existing: 42);
    $dispatcher = new GitHubIssueDispatcher($cli, 'particle-academy/prism', enabled: true);

    $lines = $dispatcher->dispatch(driftReport());

    expect($lines[0])->toContain('already exists')
        ->and($cli->created)->toBe(0);
});

it('degrades gracefully when gh is unavailable', function () {
    $cli = fakeCli(available: false);
    $dispatcher = new GitHubIssueDispatcher($cli, 'particle-academy/prism', enabled: true);

    expect($dispatcher->dispatch(driftReport())[0])->toContain('not available');
});

it('files nothing on a day that only moved a catalogue', function () {
    // A day where an aggregator added and dropped some models needs no work
    // from anyone. Filing it anyway put 22 reports-on-nothing into the product
    // intake against one that asked for a change, which is how a channel stops
    // being read. The catalogue is still diffed, archived and reported; it
    // just doesn't open an issue.
    $cli = fakeCli(existing: null);
    $dispatcher = new GitHubIssueDispatcher($cli, 'particle-academy/prism', enabled: true);

    $catalogueOnly = new DriftReport([
        new Change('openrouter', 'models-dev', ChangeType::Added, 'some-free-model'),
        new Change('openrouter', 'models-dev', ChangeType::Removed, 'another-free-model'),
    ], [], '2026-07-25T00:00:00Z');

    $lines = $dispatcher->dispatch($catalogueOnly);

    expect($cli->created)->toBe(0)
        ->and($lines[0])->toContain('no contract changes');
});

it('still files when a contract moved, even beside catalogue noise', function () {
    // The control. A request or response shape moving is the one class of
    // drift that breaks a running application, and it must still arrive.
    $cli = fakeCli(existing: null);
    $dispatcher = new GitHubIssueDispatcher($cli, 'particle-academy/prism', enabled: true);

    $lines = $dispatcher->dispatch(new DriftReport([
        new Change('openrouter', 'models-dev', ChangeType::Added, 'some-free-model'),
        new Change('openai', 'openapi-spec', ChangeType::Changed, 'POST /v1/responses'),
    ], [], '2026-07-25T00:00:00Z'));

    expect($cli->created)->toBe(1)
        ->and($lines[0])->toContain('opened');
});
