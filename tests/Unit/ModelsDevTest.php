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

/*
 * G-291: prices. The fixture is an excerpt of the real models.dev api.json
 * (fetched 2026-11-15), whole provider and model objects untouched, so the
 * parser meets the shape models.dev actually publishes: integer and float costs
 * mixed, cache_write absent on gpt-4o, both cache fields absent on gpt-4-turbo,
 * no `cost` at all on gpt-image-2, and extra cost keys (reasoning) on DeepSeek
 * that are not recorded.
 */
function mdevFixtureBody(): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/models-dev-api.json');
}

function mdevFixtureCatalog(?string $body = null): ModelsDevCatalog
{
    return new ModelsDevCatalog(new ModelsDevFakeHttp(new HttpResponse(200, $body ?? mdevFixtureBody())), 'x');
}

it('records the four price fields per model from the real catalogue shape', function () {
    expect(mdevFixtureCatalog()->pricesFor('anthropic'))->toBe([
        'claude-haiku-4-5' => ['input' => 1.0, 'output' => 5.0, 'cache_read' => 0.1, 'cache_write' => 1.25],
        'claude-sonnet-4-5' => ['input' => 3.0, 'output' => 15.0, 'cache_read' => 0.3, 'cache_write' => 3.75],
    ]);
});

it('records a missing price as null, never 0', function () {
    $prices = mdevFixtureCatalog()->pricesFor('openai') ?? [];

    expect($prices['gpt-4o'])->toBe(['input' => 2.5, 'output' => 10.0, 'cache_read' => 1.25, 'cache_write' => null])
        ->and($prices['gpt-4-turbo'])->toBe(['input' => 10.0, 'output' => 30.0, 'cache_read' => null, 'cache_write' => null])
        ->and($prices['gpt-image-2'])->toBe(['input' => null, 'output' => null, 'cache_read' => null, 'cache_write' => null]);
});

it('records only the four calibrated fields, ignoring extra cost keys', function () {
    $prices = mdevFixtureCatalog()->pricesFor('deepseek') ?? [];

    expect(array_keys($prices['deepseek-v4-pro']))->toBe(ModelsDevCatalog::PRICE_FIELDS);
});

it('puts prices in the snapshot, keyed and ordered like the roster', function () {
    $result = (new ModelsDevSource(mdevFixtureCatalog(), 'models-dev', 'OpenAI', 'openai'))->poll();

    expect($result->data['models'])->toBe(['gpt-4-turbo', 'gpt-4o', 'gpt-image-2'])
        ->and(array_keys($result->data['prices']))->toBe($result->data['models']);
});

it('serialises an unchanged upstream to byte-identical JSON whatever the upstream key order', function () {
    $shuffled = json_decode(mdevFixtureBody(), true);
    $shuffled['openai']['models'] = array_reverse($shuffled['openai']['models'], true);
    $shuffled['openai']['models']['gpt-4o']['cost'] = array_reverse($shuffled['openai']['models']['gpt-4o']['cost'], true);

    $poll = static fn (string $body): string => (string) json_encode(
        (new ModelsDevSource(mdevFixtureCatalog($body), 'models-dev', 'OpenAI', 'openai'))->poll()->toArray(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    expect($poll((string) json_encode($shuffled)))->toBe($poll(mdevFixtureBody()));
});

it('fails cleanly when models.dev is unreachable', function () {
    $catalog = new ModelsDevCatalog(new ModelsDevFakeHttp(new HttpResponse(0, 'timeout')), 'x');

    $result = (new ModelsDevSource($catalog, 'models', 'OpenAI', 'openai'))->poll();

    expect($result->ok)->toBeFalse()
        ->and($result->note)->toContain('models.dev unavailable');
});
