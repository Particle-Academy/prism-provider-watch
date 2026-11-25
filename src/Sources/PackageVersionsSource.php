<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Sources;

use Prism\ProviderWatch\Http\Http;

/**
 * The published versions of one package across the channels it ships through: npm, Packagist, and git tags.
 *
 * Each version is an ENTRY, so a new release is added drift. The payload sets `report_removed`, so a version that
 * disappears (an unpublish, a deleted tag) is removed drift rather than silence: for a package we depend on, a
 * version vanishing is as much a signal as one appearing.
 *
 * A channel that cannot be read is an entry too (`unreadable:<channel>:<name>`), like McpSchemaSource's servers: one
 * dead registry must not hide the others, and a failed poll is never dispatched. An npm name that does not exist yet
 * is `unpublished:npm:<name>`, so the first publish reads as that entry going away and the versions arriving. The
 * poll fails only when no channel was readable at all.
 */
final class PackageVersionsSource implements Source
{
    /**
     * @param  list<string>  $tagRepos  owner/name of each repository whose git tags are release versions
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $id,
        private readonly string $label,
        private readonly ?string $npm = null,
        private readonly ?string $packagist = null,
        private readonly array $tagRepos = [],
        private readonly ?string $token = null,
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
        $entries = [];
        $readable = 0;

        if ($this->npm !== null && $this->npm !== '') {
            $versions = $this->npmVersions($this->npm);
            $readable += $this->collect($entries, 'npm', $this->npm, $versions, fn (string $v): string => "{$this->npm}@{$v} published on npm");
        }
        if ($this->packagist !== null && $this->packagist !== '') {
            $versions = $this->packagistVersions($this->packagist);
            $readable += $this->collect($entries, 'packagist', $this->packagist, $versions, fn (string $v): string => "{$this->packagist} {$v} published on Packagist");
        }
        $repos = $this->tagRepos;
        sort($repos, SORT_STRING);
        foreach ($repos as $repo) {
            $tags = GitHubTags::list($this->http, $repo, $this->token);
            $readable += $this->collect($entries, 'tag', $repo, $tags, fn (string $t): string => "{$repo} tagged {$t}");
        }

        if ($readable === 0) {
            return SourceResult::failed($this->id, 'No channel could be read: '.implode('; ', array_column($entries, 'title')));
        }
        usort($entries, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return SourceResult::ok($this->id, ['report_removed' => true, 'entries' => $entries]);
    }

    /**
     * @param  list<array{id: string, title: string}>  $entries
     * @param  list<string>|string|null  $versions  null: the name is not published
     * @param  callable(string): string  $title
     * @return int 1 when the channel was read, else 0
     */
    private function collect(array &$entries, string $channel, string $name, array|string|null $versions, callable $title): int
    {
        if ($versions === null) {
            $entries[] = ['id' => "unpublished:{$channel}:{$name}", 'title' => "{$name} is not published on {$channel}"];

            return 1;
        }
        if (is_string($versions)) {
            $entries[] = ['id' => "unreadable:{$channel}:{$name}", 'title' => "{$name} on {$channel}: {$versions}"];

            return 0;
        }
        foreach ($versions as $version) {
            $entries[] = ['id' => "{$channel}:{$name}@{$version}", 'title' => $title($version)];
        }

        return 1;
    }

    /** @return list<string>|string|null */
    private function npmVersions(string $package): array|string|null
    {
        $url = 'https://registry.npmjs.org/'.str_replace('/', '%2f', $package);
        $response = $this->http->get($url, ['Accept' => 'application/json']);
        if ($response->status === 404) {
            return null;
        }
        if (! $response->ok()) {
            return "the registry returned HTTP {$response->status}";
        }
        $versions = $response->json()['versions'] ?? null;
        if (! is_array($versions)) {
            return 'the registry document has no versions';
        }

        return self::sorted(array_keys($versions));
    }

    /** @return list<string>|string|null */
    private function packagistVersions(string $package): array|string|null
    {
        $url = "https://repo.packagist.org/p2/{$package}.json";
        $response = $this->http->get($url, ['Accept' => 'application/json']);
        if ($response->status === 404) {
            return null;
        }
        if (! $response->ok()) {
            return "the registry returned HTTP {$response->status}";
        }
        $releases = $response->json()['packages'][$package] ?? null;
        if (! is_array($releases) || ! array_is_list($releases)) {
            return 'the registry document does not list the package';
        }
        $versions = [];
        foreach ($releases as $release) {
            if (is_array($release) && is_string($release['version'] ?? null)) {
                $versions[] = $release['version'];
            }
        }

        return self::sorted($versions);
    }

    /**
     * @param  array<mixed>  $versions
     * @return list<string>
     */
    private static function sorted(array $versions): array
    {
        $versions = array_values(array_unique(array_map('strval', $versions)));
        sort($versions, SORT_STRING);

        return $versions;
    }
}
