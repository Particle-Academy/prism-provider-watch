<?php

declare(strict_types=1);

use Prism\ProviderWatch\Http\Http;
use Prism\ProviderWatch\Http\HttpResponse;
use Prism\ProviderWatch\Sources\ModelsDevCatalog;
use Prism\ProviderWatch\Sources\ModelsDevSource;

/** Counts GET calls so we can prove the catalog is fetched only once. */
final class ModelsDevFakeHttp implements Http
{
    public int $gets = 0;

    public function __construct(private readonly HttpResponse $response) {}

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->gets++;

        return $this->response;
    }

    public function post(string $url, string $body, array $headers = []): HttpResponse
    {
        return new HttpResponse(200, '{}');
    }
}

function mdevCatalog(): string
{
    return (string) json_encode([
        'openai' => ['id' => 'openai', 'models' => ['gpt-5' => [], 'gpt-4o' => []]],
        'anthropic' => ['id' => 'anthropic', 'models' => ['claude-opus-5' => []]],
        'google' => ['id' => 'google', 'models' => []],
    ]);
}

it('extracts a sorted, de-duplicated roster for a provider', function () {
    $catalog = new ModelsDevCatalog(new ModelsDevFakeHttp(new HttpResponse(200, mdevCatalog())), 'https://models.dev/api.json');

    expect($catalog->modelsFor('openai'))->toBe(['gpt-4o', 'gpt-5']); // sorted
});

it('fetches models.dev only once across many sources', function () {
    $http = new ModelsDevFakeHttp(new HttpResponse(200, mdevCatalog()));
    $catalog = new ModelsDevCatalog($http, 'https://models.dev/api.json');

    (new ModelsDevSource($catalog, 'models', 'OpenAI', 'openai'))->poll();
    (new ModelsDevSource($catalog, 'models', 'Anthropic', 'anthropic'))->poll();
    (new ModelsDevSource($catalog, 'models', 'OpenAI', 'openai'))->poll();

    expect($http->gets)->toBe(1);
});

it('returns the roster in the same snapshot shape as the endpoint source', function () {
    $catalog = new ModelsDevCatalog(new ModelsDevFakeHttp(new HttpResponse(200, mdevCatalog())), 'x');

    $result = (new ModelsDevSource($catalog, 'models', 'OpenAI', 'openai'))->poll();

    expect($result->ok)->toBeTrue()
        ->and($result->data['count'])->toBe(2)
        ->and($result->data['models'])->toBe(['gpt-4o', 'gpt-5']);
});

it('fails cleanly for a provider absent from the catalog', function () {
    $catalog = new ModelsDevCatalog(new ModelsDevFakeHttp(new HttpResponse(200, mdevCatalog())), 'x');

    $result = (new ModelsDevSource($catalog, 'models', 'Nope', 'does-not-exist'))->poll();

    expect($result->ok)->toBeFalse()
        ->and($result->note)->toContain("no provider 'does-not-exist'");
});

it('fails cleanly when models.dev is unreachable', function () {
    $catalog = new ModelsDevCatalog(new ModelsDevFakeHttp(new HttpResponse(0, 'timeout')), 'x');

    $result = (new ModelsDevSource($catalog, 'models', 'OpenAI', 'openai'))->poll();

    expect($result->ok)->toBeFalse()
        ->and($result->note)->toContain('models.dev unavailable');
});
