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
