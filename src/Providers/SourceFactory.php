<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Providers;

use InvalidArgumentException;
use Prism\ProviderWatch\Http\Http;
use Prism\ProviderWatch\Sources\GitHubAtomSource;
use Prism\ProviderWatch\Sources\GitHubIssuesSource;
use Prism\ProviderWatch\Sources\ModelsDevCatalog;
use Prism\ProviderWatch\Sources\ModelsDevSource;
use Prism\ProviderWatch\Sources\ModelsEndpointSource;
use Prism\ProviderWatch\Sources\Source;

/**
 * Turns a plain descriptor from config/providers.php into a live Source,
 * resolving API keys from the environment at build time. Key resolution takes
 * the first non-empty of the listed env vars, so a provider can accept
 * fallback key names (e.g. GEMINI_API_KEY then GOOGLE_API_KEY).
 */
final class SourceFactory
{
    private ?ModelsDevCatalog $modelsDev = null;

    /** @param callable(string): (string|false) $env */
    public function __construct(
        private readonly Http $http,
        private $env = 'getenv',
    ) {}

    /** @param array<string, mixed> $descriptor */
    public function make(array $descriptor): Source
    {
        $type = (string) ($descriptor['type'] ?? '');

        return match ($type) {
            'models-dev' => new ModelsDevSource(
                catalog: $this->modelsDevCatalog(),
                id: (string) $descriptor['id'],
                label: (string) ($descriptor['label'] ?? $descriptor['id']),
                providerId: (string) $descriptor['md_provider'],
            ),
            'models' => new ModelsEndpointSource(
                http: $this->http,
                id: (string) $descriptor['id'],
                label: (string) ($descriptor['label'] ?? $descriptor['id']),
                url: (string) $descriptor['url'],
                apiKey: $this->resolveKey($descriptor['key_env'] ?? []),
                auth: (string) ($descriptor['auth'] ?? ModelsEndpointSource::AUTH_BEARER),
                shape: (string) ($descriptor['shape'] ?? ModelsEndpointSource::SHAPE_OPENAI),
                extraHeaders: (array) ($descriptor['headers'] ?? []),
            ),
            'github-atom' => new GitHubAtomSource(
                http: $this->http,
                id: (string) $descriptor['id'],
                label: (string) ($descriptor['label'] ?? $descriptor['id']),
                url: (string) $descriptor['url'],
                max: (int) ($descriptor['max'] ?? 15),
            ),
            'github-issues' => new GitHubIssuesSource(
                http: $this->http,
                id: (string) $descriptor['id'],
                label: (string) ($descriptor['label'] ?? $descriptor['id']),
                repo: (string) $descriptor['repo'],
                kind: (string) ($descriptor['kind'] ?? GitHubIssuesSource::KIND_ISSUES),
                token: $this->resolveKey(['GITHUB_TOKEN', 'GH_TOKEN']) ?: null,
                max: (int) ($descriptor['max'] ?? 30),
            ),
            default => throw new InvalidArgumentException("Unknown source type: {$type}"),
        };
    }

    /** One shared catalog across every models-dev source, so models.dev is fetched once per run. */
    private function modelsDevCatalog(): ModelsDevCatalog
    {
        return $this->modelsDev ??= new ModelsDevCatalog($this->http);
    }

    /** @param string|list<string> $keyEnv */
    private function resolveKey(string|array $keyEnv): ?string
    {
        foreach ((array) $keyEnv as $name) {
            $value = ($this->env)((string) $name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
