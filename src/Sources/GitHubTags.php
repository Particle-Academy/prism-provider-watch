<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Sources;

use Prism\ProviderWatch\Http\Http;

/**
 * A repository's tags from the GitHub REST API, and the newest release tag among them.
 *
 * The REST API rather than `tags.atom` because the feed carries no stable ordering guarantee and only the newest few;
 * the API's first page of 100 is every tag a young repository has. A token lifts the rate limit from 60/hour to 5000.
 */
final class GitHubTags
{
    /**
     * Every tag name, sorted, or a short reason they could not be read.
     *
     * @return list<string>|string
     */
    public static function list(Http $http, string $repo, ?string $token): array|string
    {
        $url = "https://api.github.com/repos/{$repo}/tags?per_page=100";
        $headers = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'prism-provider-watch'];
        if ($token !== null && $token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        $response = $http->get($url, $headers);
        if (! $response->ok()) {
            return "the tag list returned HTTP {$response->status}";
        }
        $listing = $response->json();
        if ($listing === null || ! array_is_list($listing)) {
            return 'the tag list was not a JSON list';
        }
        $names = [];
        foreach ($listing as $tag) {
            if (is_array($tag) && is_string($tag['name'] ?? null) && $tag['name'] !== '') {
                $names[] = $tag['name'];
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The highest release tag (`v1.2.3` or `1.2.3`, prereleases ignored), or null when there is none.
     *
     * @param  list<string>  $tags
     */
    public static function newestRelease(array $tags): ?string
    {
        $releases = array_values(array_filter($tags, fn (string $t): bool => preg_match('/^v?\d+\.\d+\.\d+$/', $t) === 1));
        if ($releases === []) {
            return null;
        }
        usort($releases, fn (string $a, string $b): int => version_compare(ltrim($a, 'v'), ltrim($b, 'v')));

        return $releases[count($releases) - 1];
    }
}
