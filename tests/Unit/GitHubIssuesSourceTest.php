<?php

declare(strict_types=1);

use Prism\ProviderWatch\Http\Http;
use Prism\ProviderWatch\Http\HttpResponse;
use Prism\ProviderWatch\Sources\GitHubIssuesSource;

/** Records the URL and headers so we can assert what was actually requested. */
final class IssuesFakeHttp implements Http
{
    public string $lastUrl = '';

    /** @var array<string, string> */
    public array $lastHeaders = [];

    public function __construct(private readonly HttpResponse $response) {}

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        return $this->response;
    }

    public function post(string $url, string $body, array $headers = []): HttpResponse
    {
        return $this->response;
    }
}

function issuesPayload(): string
{
    return json_encode([
        ['number' => 1040, 'title' => 'Streaming drops a chunk', 'user' => ['login' => 'someone']],
        // /issues returns pull requests too — they must not surface as issues.
        ['number' => 1039, 'title' => 'fix: a pull request', 'user' => ['login' => 'dev'], 'pull_request' => ['url' => 'x']],
        ['number' => 1038, 'title' => 'Untitled but valid', 'user' => ['login' => 'other']],
    ], JSON_THROW_ON_ERROR);
}

it('lists open issues and excludes pull requests', function () {
    $http = new IssuesFakeHttp(new HttpResponse(200, issuesPayload()));

    $result = (new GitHubIssuesSource($http, 'issues', 'upstream issues', 'prism-php/prism'))->poll();

    expect($result->ok)->toBeTrue();

    $titles = array_column($result->data['entries'], 'title');

    expect($titles)->toHaveCount(2)
        ->and($titles[0])->toBe('#1040 Streaming drops a chunk (@someone)')
        ->and(implode(' ', $titles))->not->toContain('a pull request');
});

it('keys entries on the number so an edited title is not new drift', function () {
    $http = new IssuesFakeHttp(new HttpResponse(200, issuesPayload()));

    $result = (new GitHubIssuesSource($http, 'issues', 'upstream issues', 'prism-php/prism'))->poll();

    expect(array_column($result->data['entries'], 'id'))->toBe(['1040', '1038']);
});

it('asks the pulls endpoint when kind is pulls', function () {
    $http = new IssuesFakeHttp(new HttpResponse(200, '[]'));

    (new GitHubIssuesSource($http, 'pulls', 'upstream pulls', 'prism-php/prism', GitHubIssuesSource::KIND_PULLS))->poll();

    expect($http->lastUrl)->toContain('/repos/prism-php/prism/pulls')
        ->and($http->lastUrl)->toContain('state=open');
});

it('sends the token only when one is supplied', function () {
    $without = new IssuesFakeHttp(new HttpResponse(200, '[]'));
    (new GitHubIssuesSource($without, 'issues', 'l', 'o/r'))->poll();

    $with = new IssuesFakeHttp(new HttpResponse(200, '[]'));
    (new GitHubIssuesSource($with, 'issues', 'l', 'o/r', GitHubIssuesSource::KIND_ISSUES, 'ghp_x'))->poll();

    expect($without->lastHeaders)->not->toHaveKey('Authorization')
        ->and($with->lastHeaders['Authorization'])->toBe('Bearer ghp_x');
});

it('fails cleanly on a non-2xx rather than throwing', function () {
    $http = new IssuesFakeHttp(new HttpResponse(403, 'rate limited'));

    $result = (new GitHubIssuesSource($http, 'issues', 'l', 'o/r'))->poll();

    expect($result->ok)->toBeFalse()
        ->and($result->note)->toContain('403');
});

it('fails cleanly on malformed JSON', function () {
    $http = new IssuesFakeHttp(new HttpResponse(200, '{not json'));

    $result = (new GitHubIssuesSource($http, 'issues', 'l', 'o/r'))->poll();

    expect($result->ok)->toBeFalse()
        ->and($result->note)->toContain('Malformed');
});

it('is registered as a source type and wired for the upstream target', function () {
    $config = require dirname(__DIR__, 2).'/config/providers.php';

    expect($config)->toHaveKey('upstream');

    $sources = array_column($config['upstream']['sources'], null, 'id');

    expect(array_keys($sources))->toContain('issues', 'pulls')
        ->and($sources['issues']['repo'])->toBe('prism-php/prism')
        ->and($sources['pulls']['kind'])->toBe('pulls');
});
