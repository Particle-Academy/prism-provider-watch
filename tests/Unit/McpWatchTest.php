<?php

declare(strict_types=1);

use Prism\ProviderWatch\Diff\Change;
use Prism\ProviderWatch\Diff\ChangeType;
use Prism\ProviderWatch\Diff\DriftReport;

/**
 * MCP is a protocol prism speaks, not an LLM provider, but it belongs in the
 * same registry: a protocol revision breaks integrations outright, whereas a
 * new model id is a string prism passes through untouched.
 */
it('watches the MCP spec and laravel/mcp', function () {
    $config = require dirname(__DIR__, 2).'/config/providers.php';

    expect($config)->toHaveKey('mcp');

    $sources = array_column($config['mcp']['sources'], null, 'id');

    expect(array_keys($sources))->toContain('spec', 'laravel-mcp')
        ->and($sources['spec']['url'])->toContain('modelcontextprotocol/modelcontextprotocol')
        ->and($sources['laravel-mcp']['url'])->toContain('laravel/mcp');
});

it('points MCP drift at the prism surfaces that consume it', function () {
    $config = require dirname(__DIR__, 2).'/config/providers.php';

    // LaravelMcpTool is the bridge; composer.json carries the version suggest.
    expect($config['mcp']['prism_hints'])->toContain('src/Tools/LaravelMcpTool.php', 'composer.json');
});

it('titles a section with the label so a non-provider target reads correctly', function () {
    $report = new DriftReport(
        [new Change('mcp', 'laravel-mcp', ChangeType::Added, 'v1.0.0-beta.1')],
        ['mcp' => ['src/Tools/LaravelMcpTool.php']],
        '2026-08-17T07:00:00Z',
        ['mcp' => 'MCP (spec + laravel/mcp)'],
    );

    expect($report->toMarkdown())
        ->toContain('## MCP (spec + laravel/mcp)')
        ->not->toContain("## mcp\n")
        ->and($report->toMarkdown())->toContain('watch target(s)');
});

it('falls back to the key when no label is supplied', function () {
    $report = new DriftReport(
        [new Change('openai', 'models-dev', ChangeType::Added, 'gpt-6')],
        [],
        '2026-08-17T07:00:00Z',
    );

    expect($report->toMarkdown())->toContain('## openai');
});
