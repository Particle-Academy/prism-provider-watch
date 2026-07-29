<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Mcp;

use Prism\ProviderWatch\Http\Http;

/**
 * A minimal MCP client: one stateless JSON-RPC `tools/call` POST per call, the
 * same shape Genie's own `imDone` hook uses (no initialize handshake). Tolerates
 * either a plain-JSON or an SSE-framed (`data: {…}`) response. Never throws —
 * a dead endpoint or a tool error comes back as a failed {@see McpResult} so the
 * watch run always completes.
 */
final class McpClient
{
    public function __construct(
        private readonly Http $http,
        private readonly McpEndpoints $endpoints,
    ) {}

    /** @param array<string, mixed> $arguments */
    public function call(string $server, string $tool, array $arguments): McpResult
    {
        $endpoint = $this->endpoints->get($server);
        if ($endpoint === null) {
            return McpResult::failed("MCP server '{$server}' is not configured (.mcp.json).");
        }

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return McpResult::failed('Failed to encode MCP request.');
        }

        $headers = $endpoint['headers'] + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ];

        $response = $this->http->post($endpoint['url'], $payload, $headers);
        if (! $response->ok()) {
            return McpResult::failed("{$server}.{$tool} → HTTP {$response->status}");
        }

        $json = $this->decode($response->body);
        if ($json === null) {
            return McpResult::failed("{$server}.{$tool} returned an unparseable response.");
        }

        if (isset($json['error'])) {
            $message = is_array($json['error']) ? ($json['error']['message'] ?? 'error') : (string) $json['error'];

            return McpResult::failed("{$server}.{$tool}: {$message}");
        }

        $result = is_array($json['result'] ?? null) ? $json['result'] : [];
        $text = $this->extractText($result);

        return ($result['isError'] ?? false)
            ? McpResult::failed($text !== '' ? $text : 'tool reported an error')
            : McpResult::ok($text);
    }

    /** @return array<string, mixed>|null */
    private function decode(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }

        if ($body[0] === '{') {
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : null;
        }

        // SSE framing: use the last `data:` line.
        $data = null;
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $data = trim(substr($line, 5));
            }
        }

        if ($data === null) {
            return null;
        }

        $decoded = json_decode($data, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $result */
    private function extractText(array $result): string
    {
        $parts = [];
        foreach ((array) ($result['content'] ?? []) as $chunk) {
            if (is_array($chunk) && ($chunk['type'] ?? '') === 'text' && isset($chunk['text'])) {
                $parts[] = (string) $chunk['text'];
            }
        }

        return implode("\n", $parts);
    }
}
