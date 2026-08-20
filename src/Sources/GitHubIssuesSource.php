<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Sources;

use Prism\ProviderWatch\Http\Http;
use Throwable;

/**
 * Open issues or pull requests on a GitHub repo.
 *
 * GitHub publishes Atom for commits, releases and tags but NOT for issues or
 * pulls — both `issues.atom` and `pulls.atom` answer 406 — so this reads the
 * REST API instead. An unauthenticated caller gets 60 requests/hour, which a
 * daily run fits inside comfortably; a token raises it to 5000 and is used
 * when one is present.
 *
 * The `/issues` endpoint returns pull requests too, distinguished by a
 * `pull_request` key. Callers pick one kind so a report can say "new issue"
 * or "new pull request" rather than lumping them together.
 */
final class GitHubIssuesSource implements Source
{
    public const KIND_ISSUES = 'issues';

    public const KIND_PULLS = 'pulls';

    public function __construct(
        private readonly Http $http,
        private readonly string $id,
        private readonly string $label,
        private readonly string $repo,
        private readonly string $kind = self::KIND_ISSUES,
        private readonly ?string $token = null,
        private readonly int $max = 30,
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
        $path = $this->kind === self::KIND_PULLS ? 'pulls' : 'issues';
        $url = "https://api.github.com/repos/{$this->repo}/{$path}?state=open&per_page={$this->max}&sort=created&direction=desc";

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'prism-provider-watch',
        ];

        if ($this->token !== null && $this->token !== '') {
            $headers['Authorization'] = "Bearer {$this->token}";
        }

        $response = $this->http->get($url, $headers);

        if (! $response->ok()) {
            return SourceResult::failed($this->id, "GET {$url} returned HTTP {$response->status}.");
        }

        try {
            $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return SourceResult::failed($this->id, "Malformed JSON from {$url}.");
        }

        if (! is_array($decoded)) {
            return SourceResult::failed($this->id, "Unexpected payload shape from {$url}.");
        }

        $entries = [];

        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            // /issues returns pull requests as well; drop them when issues were asked for.
            if ($this->kind === self::KIND_ISSUES && isset($item['pull_request'])) {
                continue;
            }

            $number = $item['number'] ?? null;
            $title = $item['title'] ?? null;

            if (! is_int($number) || ! is_string($title)) {
                continue;
            }

            $author = $item['user']['login'] ?? null;

            $entries[] = [
                // The number keys the diff: an edited title must not read as a new item.
                'id' => (string) $number,
                'title' => "#{$number} {$title}".(is_string($author) ? " (@{$author})" : ''),
            ];
        }

        return SourceResult::ok($this->id, [
            'latest' => $entries[0]['title'] ?? '',
            'entries' => $entries,
        ]);
    }
}
