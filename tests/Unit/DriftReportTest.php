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
