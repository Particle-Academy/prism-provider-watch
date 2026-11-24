<?php

declare(strict_types=1);

use GuzzleHttp\Utils;
use Prism\ProviderWatch\Http\GuzzleHttp;
use Psr\Http\Message\RequestInterface;

require_once __DIR__.'/../Support/RedirectLoopback.php';

/*
 * Redirects on the watcher's HTTP client, against real loopback servers.
 *
 * Requests carry API keys and bearer tokens in headers. A redirect is followed
 * only when the scheme, host and port stay the same; any other redirect is
 * refused before a second request is made, so the other server receives
 * nothing at all.
 */

const WATCH_REDIRECT_KEY = 'fake-watch-key-00000000000000000000000001';

/** Guzzle's own transport, refusing anything that is not a 127.x address. */
function loopbackOnlyHandler(): callable
{
    $transport = Utils::chooseHandler();

    return static function (RequestInterface $request, array $options) use ($transport) {
        if (preg_match('/\A127\.\d+\.\d+\.\d+\z/', $request->getUri()->getHost()) !== 1) {
            throw new RuntimeException('Only loopback hosts may be contacted in this test.');
        }

        return $transport($request, $options);
    };
}

function redirectWatchHttp(): GuzzleHttp
{
    return new GuzzleHttp(5.0, loopbackOnlyHandler());
}

beforeEach(function (): void {
    $this->endpoint = RedirectLoopback::start('127.0.0.1');
    $this->elsewhere = RedirectLoopback::start('127.0.0.2');
});

afterEach(function (): void {
    $this->endpoint->stop();
    $this->elsewhere->stop();
});

it('does not follow a GET redirect to another host and port', function (int $status): void {
    $this->endpoint->answer([['match' => '/v1/models', 'status' => $status, 'location' => $this->elsewhere->origin().'/landed']]);

    $response = redirectWatchHttp()->get($this->endpoint->origin().'/v1/models', ['x-api-key' => WATCH_REDIRECT_KEY]);

    expect($this->elsewhere->requests())->toBe([])
        ->and($this->elsewhere->received())->not->toContain(WATCH_REDIRECT_KEY)
        ->and($this->endpoint->requests())->toHaveCount(1)
        ->and($response->status)->toBe(0)
        ->and($response->body)->not->toContain(WATCH_REDIRECT_KEY);
})->with([301, 302, 303, 307, 308]);

it('does not follow a POST redirect to another host and port', function (int $status): void {
    $this->endpoint->answer([['match' => '/mcp', 'status' => $status, 'location' => $this->elsewhere->origin().'/landed']]);

    $response = redirectWatchHttp()->post($this->endpoint->origin().'/mcp', '{"secret":"body"}', ['Authorization' => 'Bearer '.WATCH_REDIRECT_KEY]);

    expect($this->elsewhere->requests())->toBe([])
        ->and($this->endpoint->requests())->toHaveCount(1)
        ->and($response->status)->toBe(0);
})->with([302, 307, 308]);

it('does not follow a protocol-relative redirect to another host', function (): void {
    $this->endpoint->answer([['match' => '/v1/models', 'status' => 302, 'location' => '//'.$this->elsewhere->host.':'.$this->elsewhere->port.'/landed']]);

    $response = redirectWatchHttp()->get($this->endpoint->origin().'/v1/models', ['x-api-key' => WATCH_REDIRECT_KEY]);

    expect($this->elsewhere->requests())->toBe([])
        ->and($response->status)->toBe(0);
});

it('does not follow a redirect to the same host on another port', function (): void {
    $other = RedirectLoopback::start('127.0.0.1');

    try {
        $this->endpoint->answer([['match' => '/v1/models', 'status' => 307, 'location' => $other->origin().'/landed']]);

        $response = redirectWatchHttp()->get($this->endpoint->origin().'/v1/models', ['x-api-key' => WATCH_REDIRECT_KEY]);

        expect($other->requests())->toBe([])
            ->and($response->status)->toBe(0);
    } finally {
        $other->stop();
    }
});

it('does not follow a redirect that changes the scheme', function (): void {
    $this->endpoint->answer([['match' => '/v1/models', 'status' => 308, 'location' => 'https://{host}/landed']]);

    $response = redirectWatchHttp()->get($this->endpoint->origin().'/v1/models', ['x-api-key' => WATCH_REDIRECT_KEY]);

    expect($this->endpoint->requests())->toHaveCount(1)
        ->and($response->status)->toBe(0);
});

it('follows a redirect that keeps the scheme, host and port', function (): void {
    $this->endpoint->answer([['match' => '/v1/models', 'status' => 307, 'location' => '/landed']], body: '{"data":[]}');

    $response = redirectWatchHttp()->get($this->endpoint->origin().'/v1/models', ['x-api-key' => WATCH_REDIRECT_KEY]);

    $requests = $this->endpoint->requests();

    expect($response->status)->toBe(200)
        ->and($response->body)->toBe('{"data":[]}')
        ->and($requests)->toHaveCount(2)
        ->and($requests[1]['uri'])->toBe('/landed')
        ->and($this->elsewhere->requests())->toBe([]);
});
