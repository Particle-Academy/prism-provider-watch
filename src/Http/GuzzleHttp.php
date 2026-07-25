<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class GuzzleHttp implements Http
{
    private readonly Client $client;

    public function __construct(private readonly float $timeout = 15.0)
    {
        $this->client = new Client([
            'timeout' => $this->timeout,
            'connect_timeout' => 5.0,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'prism-provider-watch/0.1 (+https://github.com/particle-academy/prism)',
            ],
        ]);
    }

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): HttpResponse
    {
        try {
            $response = $this->client->get($url, ['headers' => $headers]);

            return new HttpResponse($response->getStatusCode(), (string) $response->getBody());
        } catch (GuzzleException $e) {
            // Transport failure (DNS, timeout, TLS). Status 0 signals "no answer"
            // so the caller reports a failed source instead of crashing the run.
            return new HttpResponse(0, $e->getMessage());
        }
    }
}
