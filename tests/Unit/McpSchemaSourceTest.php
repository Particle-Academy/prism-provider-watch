<?php

declare(strict_types=1);

use Prism\ProviderWatch\Diff\ChangeType;
use Prism\ProviderWatch\Diff\Differ;
use Prism\ProviderWatch\Http\Http;
use Prism\ProviderWatch\Http\HttpResponse;
use Prism\ProviderWatch\Snapshot\Snapshot;
use Prism\ProviderWatch\Sources\McpSchemaSource;

/** Answers by URL; anything unmapped is a 404. */
final class SchemaFakeHttp implements Http
{
    /** @var array<string, array<string, string>> */
    public array $headers = [];

    /** @param array<string, HttpResponse> $routes */
    public function __construct(private array $routes) {}

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->headers[$url] = $headers;

        return $this->routes[$url] ?? new HttpResponse(404, '');
    }

    public function post(string $url, string $body, array $headers = []): HttpResponse
    {
        return new HttpResponse(405, '');
    }
}

const SCHEMA_DIR = 'https://api.github.com/repos/modelcontextprotocol/modelcontextprotocol/contents/schema';
const TS_FILE = 'https://example.test/ts/protocol-versions.json';
const PY_FILE = 'https://example.test/py/protocol-versions.json';

/** @param list<string> $versions */
function officialListing(array $versions): HttpResponse
{
    $items = array_map(fn (string $v): array => ['name' => $v, 'type' => 'dir'], $versions);
    $items[] = ['name' => 'draft', 'type' => 'dir'];
    $items[] = ['name' => 'README.md', 'type' => 'file'];

    return new HttpResponse(200, json_encode($items, JSON_THROW_ON_ERROR));
}

/** @param list<string> $versions */
function served(array $versions): HttpResponse
{
    return new HttpResponse(200, json_encode(['versions' => $versions], JSON_THROW_ON_ERROR));
}

const FIVE = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25', '2026-07-28'];

function schemaSource(Http $http, ?string $token = null): McpSchemaSource
{
    return new McpSchemaSource($http, 'server-versions', 'servers vs schema', [
        'prism-mcp-server-ts' => TS_FILE,
        'prism-mcp-server-py' => PY_FILE,
    ], 5, $token);
}

/** @return list<string> */
function entryIds(array $data): array
{
    return array_column($data['entries'], 'id');
}

it('reports only the five newest official versions when every server serves them', function () {
    $http = new SchemaFakeHttp([
        SCHEMA_DIR => officialListing(['2024-10-07', ...FIVE]),
        TS_FILE => served(array_reverse(FIVE)),
        PY_FILE => served(FIVE),
    ]);

    $result = schemaSource($http)->poll();

    expect($result->ok)->toBeTrue()
        ->and($result->data['latest'])->toBe('2026-07-28')
        ->and(entryIds($result->data))->toBe(array_map(fn (string $v): string => "official:{$v}", array_reverse(FIVE)))
        ->and(implode(' ', entryIds($result->data)))->not->toContain('2024-10-07')
        ->and(implode(' ', entryIds($result->data)))->not->toContain('draft');
});

it('names every version a server does not serve', function () {
    $http = new SchemaFakeHttp([
        SCHEMA_DIR => officialListing(FIVE),
        TS_FILE => served(FIVE),
        PY_FILE => served(['2026-07-28']),
    ]);

    $ids = entryIds(schemaSource($http)->poll()->data);

    expect($ids)->toContain('gap:prism-mcp-server-py:2025-11-25', 'gap:prism-mcp-server-py:2024-11-05')
        ->and(array_filter($ids, fn (string $id): bool => str_starts_with($id, 'gap:')))->toHaveCount(4)
        ->and(implode(' ', $ids))->not->toContain('gap:prism-mcp-server-ts');
});

it('reports an unreadable server list as an entry, not a quiet failure', function () {
    $http = new SchemaFakeHttp([
        SCHEMA_DIR => officialListing(FIVE),
        TS_FILE => served(FIVE),
        PY_FILE => new HttpResponse(200, '{"versions": "2026-07-28"}'),
    ]);

    $result = schemaSource($http)->poll();

    expect($result->ok)->toBeTrue()
        ->and(entryIds($result->data))->toContain('unreadable:prism-mcp-server-py');

    $missing = schemaSource(new SchemaFakeHttp([SCHEMA_DIR => officialListing(FIVE), TS_FILE => served(FIVE)]))->poll();
    expect(entryIds($missing->data))->toContain('unreadable:prism-mcp-server-py');
});

it('fails the poll only when the official list cannot be read', function () {
    expect(schemaSource(new SchemaFakeHttp([]))->poll()->ok)->toBeFalse()
        ->and(schemaSource(new SchemaFakeHttp([SCHEMA_DIR => new HttpResponse(200, '{"message":"x"}')]))->poll()->ok)->toBeFalse()
        ->and(schemaSource(new SchemaFakeHttp([SCHEMA_DIR => officialListing([])]))->poll()->ok)->toBeFalse();
});

it('turns a new official version our servers lack into drift against a gap-free baseline', function () {
    $before = schemaSource(new SchemaFakeHttp([
        SCHEMA_DIR => officialListing(FIVE), TS_FILE => served(FIVE), PY_FILE => served(FIVE),
    ]))->poll();
    $after = schemaSource(new SchemaFakeHttp([
        SCHEMA_DIR => officialListing([...FIVE, '2027-03-01']), TS_FILE => served(FIVE), PY_FILE => served(FIVE),
    ]))->poll();

    $changes = (new Differ)->diff(
        new Snapshot('mcp', ['server-versions' => $before]),
        new Snapshot('mcp', ['server-versions' => $after]),
    );

    $subjects = array_map(fn ($change): string => $change->subject, $changes);
    expect($changes)->each(fn ($change) => $change->type->toBe(ChangeType::Added))
        ->and(implode("\n", $subjects))->toContain('MCP schema 2027-03-01')
        ->and(implode("\n", $subjects))->toContain('prism-mcp-server-ts does not serve MCP 2027-03-01')
        ->and(implode("\n", $subjects))->toContain('prism-mcp-server-py does not serve MCP 2027-03-01');
});

it('authenticates the GitHub listing only when a token is configured', function () {
    $http = new SchemaFakeHttp([SCHEMA_DIR => officialListing(FIVE), TS_FILE => served(FIVE), PY_FILE => served(FIVE)]);
    schemaSource($http)->poll();
    expect($http->headers[SCHEMA_DIR])->not->toHaveKey('Authorization')
        ->and($http->headers[TS_FILE])->not->toHaveKey('Authorization');

    $http = new SchemaFakeHttp([SCHEMA_DIR => officialListing(FIVE), TS_FILE => served(FIVE), PY_FILE => served(FIVE)]);
    schemaSource($http, 'fixture-token')->poll();
    // The token goes to GitHub's API only, never to a server's published file.
    expect($http->headers[SCHEMA_DIR]['Authorization'])->toBe('Bearer fixture-token')
        ->and($http->headers[TS_FILE])->not->toHaveKey('Authorization');
});

it('is configured for both of our MCP servers', function () {
    $config = require dirname(__DIR__, 2).'/config/providers.php';
    $sources = array_column($config['mcp']['sources'], null, 'id');

    expect($sources['server-versions']['type'])->toBe('mcp-schema')
        ->and($sources['server-versions']['keep'])->toBe(5)
        ->and(array_keys($sources['server-versions']['servers']))->toBe(['prism-mcp-server-ts', 'prism-mcp-server-py']);
});
