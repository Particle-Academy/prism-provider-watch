<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Sources;

use Prism\ProviderWatch\Http\Http;

/**
 * Polls a provider's "list models" endpoint and normalizes it to a sorted,
 * de-duplicated roster of model IDs. Handles the three auth styles and two
 * response shapes we care about:
 *
 *   - bearer   + openai-shaped  { data: [ { id } ] }   (OpenAI, Groq, xAI, Mistral, DeepSeek…)
 *   - x-api-key + openai-shaped  { data: [ { id } ] }   (Anthropic)
 *   - query    + google-shaped   { models: [ { name: "models/…" } ] } (Gemini)
 *
 * No key configured → skipped (actionable note), never a failure.
 */
final class ModelsEndpointSource implements Source
{
    public const AUTH_BEARER = 'bearer';

    public const AUTH_X_API_KEY = 'x-api-key';

    public const AUTH_QUERY = 'query';

    public const SHAPE_OPENAI = 'openai';

    public const SHAPE_GOOGLE = 'google';

    /** @param array<string, string> $extraHeaders */
    public function __construct(
        private readonly Http $http,
        private readonly string $id,
        private readonly string $label,
        private readonly string $url,
        private readonly ?string $apiKey,
        private readonly string $auth = self::AUTH_BEARER,
        private readonly string $shape = self::SHAPE_OPENAI,
        private readonly array $extraHeaders = [],
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function poll(): SourceResult
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            return SourceResult::skipped(
                $this->id,
                "No API key set — export the provider key to include {$this->label}.",
            );
        }

        [$url, $headers] = $this->authorize();
        $response = $this->http->get($url, $headers);

        if (! $response->ok()) {
            return SourceResult::failed(
                $this->id,
                "GET {$this->url} returned HTTP {$response->status}.",
            );
        }

        $payload = $response->json();
        if ($payload === null) {
            return SourceResult::failed($this->id, "GET {$this->url} returned non-JSON.");
        }

        $models = $this->extractModelIds($payload);
        sort($models);

        return SourceResult::ok($this->id, [
            'count' => count($models),
            'models' => array_values(array_unique($models)),
        ]);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function authorize(): array
    {
        $headers = $this->extraHeaders;

        return match ($this->auth) {
            self::AUTH_X_API_KEY => [$this->url, $headers + ['x-api-key' => (string) $this->apiKey]],
            self::AUTH_QUERY => [
                $this->url.(str_contains($this->url, '?') ? '&' : '?').'key='.urlencode((string) $this->apiKey),
                $headers,
            ],
            default => [$this->url, $headers + ['Authorization' => 'Bearer '.$this->apiKey]],
        };
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private function extractModelIds(array $payload): array
    {
        $ids = [];

        if ($this->shape === self::SHAPE_GOOGLE) {
            foreach (($payload['models'] ?? []) as $model) {
                $name = is_array($model) ? ($model['name'] ?? null) : null;
                if (is_string($name)) {
                    // "models/gemini-1.5-pro" → "gemini-1.5-pro"
                    $ids[] = str_starts_with($name, 'models/') ? substr($name, 7) : $name;
                }
            }

            return $ids;
        }

        foreach (($payload['data'] ?? []) as $model) {
            $id = is_array($model) ? ($model['id'] ?? null) : null;
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
