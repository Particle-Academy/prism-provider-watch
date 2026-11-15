<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Sources;

use Prism\ProviderWatch\Http\Http;

/**
 * Whether our MCP servers serve the newest official MCP schema versions.
 *
 * Standing rule (Wish, 2026-11-14): every MCP server we ship supports the FIVE newest official schema versions. The
 * official list is the version directories of modelcontextprotocol/modelcontextprotocol `schema/` (dated names only,
 * so `draft` is not a version). Each server publishes what it serves in its repository's `protocol-versions.json`.
 *
 * Gaps are reported as ENTRIES, not as a failed poll: a failed poll is an operational note and is never dispatched,
 * and a violation of the rule must reach someone. Against a gap-free baseline, a new official version that a server
 * does not serve yet appears as added drift ("official:<v>" plus one "gap:<server>:<v>" per server), and so does a
 * server whose published list regresses or cannot be read. Only an unreadable OFFICIAL list fails the poll, because
 * then there is nothing to compare against.
 */
final class McpSchemaSource implements Source
{
    private const SCHEMA_DIRECTORY = 'https://api.github.com/repos/modelcontextprotocol/modelcontextprotocol/contents/schema';

    /**
     * @param  array<string, string>  $servers  server name => URL of its protocol-versions.json
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $id,
        private readonly string $label,
        private readonly array $servers,
        private readonly int $keep = 5,
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
        $headers = ['Accept' => 'application/vnd.github+json'];
        if ($this->token !== null && $this->token !== '') {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }
        $response = $this->http->get(self::SCHEMA_DIRECTORY, $headers);
        if (! $response->ok()) {
            return SourceResult::failed($this->id, 'GET '.self::SCHEMA_DIRECTORY." returned HTTP {$response->status}.");
        }
        $listing = $response->json();
        if ($listing === null || ! array_is_list($listing)) {
            return SourceResult::failed($this->id, 'The MCP schema directory listing was not a JSON list.');
        }

        $official = [];
        foreach ($listing as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'dir' && is_string($item['name'] ?? null)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['name']) === 1) {
                $official[] = $item['name'];
            }
        }
        if ($official === []) {
            return SourceResult::failed($this->id, 'The MCP schema directory lists no dated versions.');
        }
        rsort($official, SORT_STRING);
        $newest = array_slice($official, 0, $this->keep);

        $entries = [];
        foreach ($newest as $version) {
            $entries[] = ['id' => "official:{$version}", 'title' => "MCP schema {$version} is one of the {$this->keep} newest official versions"];
        }

        $servers = $this->servers;
        ksort($servers, SORT_STRING);
        foreach ($servers as $server => $url) {
            $served = $this->served($url);
            if (is_string($served)) {
                $entries[] = ['id' => "unreadable:{$server}", 'title' => "{$server}: {$served}, so its supported MCP versions cannot be checked"];

                continue;
            }
            foreach ($newest as $version) {
                if (! in_array($version, $served, true)) {
                    $entries[] = [
                        'id' => "gap:{$server}:{$version}",
                        'title' => "{$server} does not serve MCP {$version}, one of the {$this->keep} newest official versions (standing rule: support all of them)",
                    ];
                }
            }
        }

        return SourceResult::ok($this->id, ['latest' => $newest[0], 'entries' => $entries]);
    }

    /**
     * The versions a server publishes, or a short reason it could not be read.
     *
     * @return list<string>|string
     */
    private function served(string $url): array|string
    {
        $response = $this->http->get($url, ['Accept' => 'application/json']);
        if (! $response->ok()) {
            return "protocol-versions.json returned HTTP {$response->status}";
        }
        $versions = $response->json()['versions'] ?? null;
        if (! is_array($versions) || ! array_is_list($versions) || $versions === []) {
            return 'protocol-versions.json has no versions list';
        }
        foreach ($versions as $version) {
            if (! is_string($version)) {
                return 'protocol-versions.json lists a version that is not a string';
            }
        }

        /** @var list<string> $versions */
        return $versions;
    }
}
