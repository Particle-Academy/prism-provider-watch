<?php

declare(strict_types=1);

use Prism\ProviderWatch\Diff\Change;
use Prism\ProviderWatch\Diff\ChangeType;
use Prism\ProviderWatch\Diff\DriftReport;

it('renders a clean no-drift report', function () {
    $report = new DriftReport([], [], '2026-07-25T00:00:00Z');

    expect($report->hasDrift())->toBeFalse()
        ->and($report->toMarkdown())->toContain('no drift');
});

it('groups changes by provider and surfaces the prism hints to review', function () {
    $report = new DriftReport(
        [
            new Change('openai', 'models', ChangeType::Added, 'gpt-5'),
            new Change('anthropic', 'models', ChangeType::Added, 'claude-opus-5'),
        ],
        [
            'openai' => ['src/Providers/OpenAI/'],
            'anthropic' => ['src/Providers/Anthropic/'],
        ],
        '2026-07-25T00:00:00Z',
    );

    $md = $report->toMarkdown();

    expect($report->hasDrift())->toBeTrue()
        ->and($report->byProvider())->toHaveKeys(['anthropic', 'openai'])
        ->and($md)->toContain('gpt-5')
        ->and($md)->toContain('claude-opus-5')
        ->and($md)->toContain('src/Providers/OpenAI/');
});

it('serializes to a stable array for the JSON artifact', function () {
    $report = new DriftReport([new Change('openai', 'models', ChangeType::Added, 'gpt-5')], [], '2026-07-25T00:00:00Z');

    expect($report->toArray())->toMatchArray([
        'has_drift' => true,
        'change_count' => 1,
    ]);
});

it('groups targets into sections so a competitor is not filed as provider drift', function (): void {
    // A provider drifting asks "what in prism do we change". A competitor
    // shipping asks "does this change what we should build". Same machinery,
    // different questions — one heading and one follow-up line cannot serve
    // both without reading wrong for one of them.
    $report = new DriftReport(
        changes: [
            new Change('openai', 'models-dev', ChangeType::Added, 'gpt-9', 'new model'),
            new Change('laravel-ai', 'releases', ChangeType::Added, 'v0.12.0', 'new release'),
        ],
        prismHints: ['openai' => ['src/Providers/OpenAI/']],
        generatedAt: '2026-08-25T12:00:00Z',
        labels: ['openai' => 'OpenAI', 'laravel-ai' => 'Laravel AI SDK'],
        categories: ['openai' => 'provider', 'laravel-ai' => 'competitor'],
        why: ['laravel-ai' => 'The official Laravel SDK, and the closest thing to a direct competitor.'],
    );

    $markdown = $report->toMarkdown();

    expect($markdown)->toContain('# Providers')
        ->toContain('# Competitors')
        ->toContain('Read for direction, not for feature envy')
        // The rationale travels with the target, so a reader who did not add
        // it still knows what a change there would mean.
        ->toContain('closest thing to a direct competitor')
        // Prism hints belong to providers only — a competitor release has no
        // "surfaces to review".
        ->toContain('src/Providers/OpenAI/');

    $providers = substr($markdown, strpos($markdown, '# Providers'), strpos($markdown, '# Competitors') - strpos($markdown, '# Providers'));

    expect($providers)->toContain('OpenAI')
        ->and($providers)->not->toContain('Laravel AI SDK');
});

it('falls back to the provider section for a target with no category', function (): void {
    // Every existing target predates categories. None of them should vanish
    // from the report because of that.
    $report = new DriftReport(
        changes: [new Change('openai', 'models-dev', ChangeType::Added, 'gpt-9', '')],
        generatedAt: '2026-08-25T12:00:00Z',
        labels: ['openai' => 'OpenAI'],
    );

    expect($report->toMarkdown())->toContain('# Providers')
        ->toContain('OpenAI');
});

/*
|--------------------------------------------------------------------------
| Getting the notice read
|--------------------------------------------------------------------------
|
| Detection was never the problem. gpt-6-astra was caught on 2026-09-04 and
| nobody noticed for five days; three drift issues sat open and unread while one
| of them carried a provider adding 503 to every inference endpoint, and Prism's
| OpenAI handler went on mapping only 529.
|
| These cover what changed as a result: the report says what it DID as well as
| what it saw, and the headline leads with the contract rather than a count that
| trains a reader to skip it.
|
*/

it('separates a contract change from catalogue churn', function () {
    $report = new DriftReport([
        new Change('openrouter', 'models-dev', ChangeType::Added, 'a:free'),
        new Change('openai', 'openapi-spec', ChangeType::Added, 'Add 503 to inference endpoints'),
        new Change('openrouter', 'models-dev', ChangeType::Added, 'b:free'),
    ]);

    expect($report->notable())->toHaveCount(1)
        ->and($report->notable()[0]->subject)->toBe('Add 503 to inference endpoints');
});

it('leads the headline with the contract, not the total', function () {
    $report = new DriftReport([
        new Change('openrouter', 'models-dev', ChangeType::Added, 'a:free'),
        new Change('openai', 'openapi-spec', ChangeType::Added, 'Add 503'),
        new Change('openrouter', 'models-dev', ChangeType::Added, 'b:free'),
    ]);

    // The old title said "3 change(s)" and buried the only one that mattered.
    expect($report->headline())->toBe('1 contract change(s) (+2 catalogue)');
});

it('says plainly when nothing structural moved', function () {
    $report = new DriftReport([
        new Change('openrouter', 'models-dev', ChangeType::Added, 'a:free'),
        new Change('openrouter', 'models-dev', ChangeType::Removed, 'b:free'),
    ]);

    expect($report->headline())->toBe('2 catalogue change(s), no contract changes')
        ->and($report->notable())->toBe([]);
});

it('records what was dispatched, so "was anyone told" is answerable', function () {
    // The archive recorded what CHANGED and never what was DONE about it, which
    // is why the 2026-09-04 astra detection cannot be explained from disk.
    $report = (new DriftReport([new Change('openai', 'openapi-spec', ChangeType::Added, 'Add 503')]))
        ->withDispatch(['GitHub issue opened: https://example.test/1', 'AgentInbox: drift sent to agent-1.']);

    expect($report->toArray()['dispatch'])->toHaveCount(2)
        ->and($report->toArray()['dispatch'][1])->toContain('AgentInbox');
});

it('carries an empty dispatch list rather than omitting the key', function () {
    // An archived report with no dispatch line must be distinguishable from one
    // written before the field existed.
    expect((new DriftReport([new Change('openai', 'openapi-spec', ChangeType::Added, 'x')]))->toArray())
        ->toHaveKey('dispatch');
});

it('never lets triage decide what is RECORDED', function () {
    // Triage decides the headline. Today's noise is tomorrow's deprecation, so
    // every change stays in the report and in the count.
    $report = new DriftReport([
        new Change('openrouter', 'models-dev', ChangeType::Added, 'a:free'),
        new Change('openai', 'openapi-spec', ChangeType::Added, 'Add 503'),
        new Change('openrouter', 'models-dev', ChangeType::Added, 'b:free'),
    ]);

    expect($report->toArray()['changes'])->toHaveCount(3)
        ->and($report->toArray()['change_count'])->toBe(3);
});
