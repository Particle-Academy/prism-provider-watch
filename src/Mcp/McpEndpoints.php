<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Mcp;

/**
 * Resolves MCP server endpoints (URL + auth headers) from the envelope's
 * `.mcp.json`, with the Genie local URL overridable by the `$GENIE_MCP_URL`
 * env Genie injects. Secrets (e.g. the Tynn bearer token) are read at runtime
 * and never persisted or logged by this tool.
 */
final class McpEndpoints
{
    /** @param array<string, array{url: string, headers: array<string, string>}> $servers */
    private function __construct(private readonly array $servers) {}

    /** @return array{url: string, headers: array<string, string>}|null */
    public function get(string $name): ?array
    {
        return $this->servers[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->servers[$name]);
    }

    public static function load(string $configPath, ?string $genieUrlOverride = null): self
    {
        $servers = [];

        if (is_file($configPath)) {
            $config = json_decode((string) file_get_contents($configPath), true);
            foreach ((array) ($config['mcpServers'] ?? []) as $name => $spec) {
                if (! is_array($spec) || empty($spec['url'])) {
                    continue;
                }
                $servers[(string) $name] = [
                    'url' => (string) $spec['url'],
                    'headers' => array_map('strval', (array) ($spec['headers'] ?? [])),
                ];
            }
        }

        // Genie sets $GENIE_MCP_URL in the environment; prefer it for the local
        // server so the tool works even if .mcp.json is absent or stale.
        if ($genieUrlOverride !== null && $genieUrlOverride !== '') {
            $servers['genie'] = [
                'url' => $genieUrlOverride,
                'headers' => $servers['genie']['headers'] ?? [],
            ];
        }

        return new self($servers);
    }
}
