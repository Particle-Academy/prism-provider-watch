<?php

declare(strict_types=1);

use Prism\ProviderWatch\Diff\Change;
use Prism\ProviderWatch\Diff\ChangeType;
use Prism\ProviderWatch\Diff\DriftReport;
use Prism\ProviderWatch\Dispatch\GenieKnowledgeDispatcher;
use Prism\ProviderWatch\Dispatch\TynnStoryDispatcher;
use Prism\ProviderWatch\Http\Http;
use Prism\ProviderWatch\Http\HttpResponse;
use Prism\ProviderWatch\Mcp\McpClient;
use Prism\ProviderWatch\Mcp\McpEndpoints;

/** Records every POST and answers tools/call keyed by tool name. */
final class McpFakeHttp implements Http
{
    /** @var list<array{url: string, body: string, headers: array<string, string>}> */
    public array $posts = [];

    /** @var array<string, HttpResponse> */
    public array $responses = [];

    public function get(string $url, array $headers = []): HttpResponse
    {
        return new HttpResponse(200, '{}');
    }

    public function post(string $url, string $body, array $headers = []): HttpResponse
    {
        $this->posts[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
        $tool = json_decode($body, true)['params']['name'] ?? '';

        return $this->responses[$tool] ?? self::result(['content' => [['type' => 'text', 'text' => 'ok']]]);
    }

    /** @param array<string, mixed> $result */
    public static function result(array $result): HttpResponse
    {
        return new HttpResponse(200, (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]));
    }

    /** @return list<string> tool names, in call order */
    public function toolNames(): array
    {
        return array_map(fn (array $p): string => json_decode($p['body'], true)['params']['name'], $this->posts);
    }

    /** @return array<string, mixed> */
    public function arguments(int $i): array
    {
        return json_decode($this->posts[$i]['body'], true)['params']['arguments'];
    }
}

function mcpEndpoints(): McpEndpoints
{
    $dir = sys_get_temp_dir().'/pw-mcp-'.bin2hex(random_bytes(5));
    mkdir($dir, 0o755, true);
    file_put_contents($dir.'/.mcp.json', (string) json_encode(['mcpServers' => [
        'genie' => ['url' => 'http://127.0.0.1:9/mcp/local'],
        'tynn' => ['url' => 'https://tynn.example/mcp', 'headers' => ['Authorization' => 'Bearer test-token']],
    ]]));

    return McpEndpoints::load($dir.'/.mcp.json', null);
}

function mcpDrift(): DriftReport
{
    return new DriftReport(
        [
            new Change('anthropic', 'sdk-python', ChangeType::Added, 'v1.0'),
            new Change('openai', 'models', ChangeType::Added, 'gpt-6'),
        ],
        ['openai' => ['src/Providers/OpenAI/'], 'anthropic' => ['src/Providers/Anthropic/']],
        '2026-07-27T00:00:00Z',
    );
}

it('genie: does nothing when disabled', function () {
    $http = new McpFakeHttp;
    $lines = (new GenieKnowledgeDispatcher(new McpClient($http, mcpEndpoints()), false))->dispatch(mcpDrift());

    expect($lines[0])->toContain('disabled')
        ->and($http->posts)->toHaveCount(0);
});

it('genie: does nothing when there is no drift', function () {
    $http = new McpFakeHttp;
    $lines = (new GenieKnowledgeDispatcher(new McpClient($http, mcpEndpoints()), true))
        ->dispatch(new DriftReport([], [], '2026-07-27T00:00:00Z'));

    expect($lines)->toBe([])->and($http->posts)->toHaveCount(0);
});

it('genie: adds a knowledge node on drift', function () {
    $http = new McpFakeHttp;
    $lines = (new GenieKnowledgeDispatcher(new McpClient($http, mcpEndpoints()), true))->dispatch(mcpDrift());

    expect($http->posts)->toHaveCount(1)
        ->and($http->toolNames())->toBe(['knowledge'])
        ->and($http->arguments(0)['action'])->toBe('add')
        ->and($lines[0])->toContain('recorded');
});

it('tynn: does nothing when disabled', function () {
    $http = new McpFakeHttp;
    (new TynnStoryDispatcher(new McpClient($http, mcpEndpoints()), false, 'V'))->dispatch(mcpDrift());

    expect($http->posts)->toHaveCount(0);
});

it('tynn: files one story per provider under the configured version', function () {
    $http = new McpFakeHttp;
    (new TynnStoryDispatcher(new McpClient($http, mcpEndpoints()), true, 'VER_EXPLICIT'))->dispatch(mcpDrift());

    // No `next` call because the version is configured; one `create` per provider.
    expect($http->toolNames())->toBe(['create', 'create'])
        ->and($http->arguments(0)['a'])->toBe('story')
        ->and($http->arguments(0)['on']['version_id'])->toBe('VER_EXPLICIT')
        ->and($http->posts[0]['headers']['Authorization'])->toBe('Bearer test-token');
});

it('tynn: resolves the active version via next when none is configured', function () {
    $http = new McpFakeHttp;
    $http->responses['next'] = McpFakeHttp::result([
        'content' => [['type' => 'text', 'text' => (string) json_encode(['active_version' => ['id' => 'VER_RESOLVED']])]],
    ]);

    (new TynnStoryDispatcher(new McpClient($http, mcpEndpoints()), true, null))->dispatch(mcpDrift());

    expect($http->toolNames())->toBe(['next', 'create', 'create'])
        ->and($http->arguments(1)['on']['version_id'])->toBe('VER_RESOLVED');
});

it('mcp: surfaces a JSON-RPC error as a failed dispatch note', function () {
    $http = new McpFakeHttp;
    $http->responses['knowledge'] = new HttpResponse(200, (string) json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'access denied'],
    ]));

    $lines = (new GenieKnowledgeDispatcher(new McpClient($http, mcpEndpoints()), true))->dispatch(mcpDrift());

    expect($lines[0])->toContain('access denied');
});

it('mcp: decodes an SSE-framed response', function () {
    $http = new McpFakeHttp;
    $http->responses['knowledge'] = new HttpResponse(200,
        "event: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => 'ok']]]])."\n\n"
    );

    $res = (new McpClient($http, mcpEndpoints()))->call('genie', 'knowledge', ['action' => 'add', 'title' => 'x']);

    expect($res->ok)->toBeTrue()->and($res->text)->toBe('ok');
});
