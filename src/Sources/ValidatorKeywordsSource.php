<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Sources;

use Prism\ProviderWatch\Http\Http;

/**
 * Which JSON Schema keywords each implementation of a validator evaluates, read at its newest release tag.
 *
 * The keyword set is read from the implementation's own source at the tag, which is the authority on what that
 * release evaluates: a README can claim a keyword the code does not implement, and the code is what a consumer runs.
 * Two extractors, one per shape the source declares its keywords in:
 *
 *   ts-interface — the property names of `export interface SchemaObject { ... }`
 *   php-cases    — the `case '<keyword>':` labels of the compiler's switch, plus the names in its ANNOTATIONS constant
 *                  (reported separately: an annotation is accepted, not evaluated)
 *
 * Every keyword is an ENTRY (`keyword:<impl>:<kw>`, `annotation:<impl>:<kw>`) and the payload sets `report_removed`,
 * so a release that adds a keyword is added drift and one that drops a keyword is removed drift. An implementation
 * that cannot be read, has no release tag, or whose source no longer yields any keyword is an `unreadable:<impl>`
 * entry rather than a failed poll, so a refactor upstream surfaces as drift instead of going quiet. The poll fails
 * only when no implementation could be read.
 */
final class ValidatorKeywordsSource implements Source
{
    public const EXTRACT_TS_INTERFACE = 'ts-interface';

    public const EXTRACT_PHP_CASES = 'php-cases';

    /**
     * @param  array<string, array{repo: string, path: string, extract: string}>  $implementations  name => where to read it
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $id,
        private readonly string $label,
        private readonly array $implementations,
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
        $releases = [];
        $implementations = $this->implementations;
        ksort($implementations, SORT_STRING);

        foreach ($implementations as $name => $where) {
            $read = $this->read($where);
            if (is_string($read)) {
                $entries[] = ['id' => "unreadable:{$name}", 'title' => "{$name}: {$read}, so its keyword support cannot be checked"];

                continue;
            }
            [$tag, $keywords, $annotations] = $read;
            $releases[$name] = $tag;
            foreach ($keywords as $keyword) {
                $entries[] = ['id' => "keyword:{$name}:{$keyword}", 'title' => "{$name} {$tag} evaluates {$keyword}"];
            }
            foreach ($annotations as $keyword) {
                $entries[] = ['id' => "annotation:{$name}:{$keyword}", 'title' => "{$name} {$tag} accepts {$keyword} as an annotation"];
            }
        }

        if ($releases === []) {
            return SourceResult::failed($this->id, 'No implementation could be read: '.implode('; ', array_column($entries, 'title')));
        }
        usort($entries, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return SourceResult::ok($this->id, ['report_removed' => true, 'releases' => $releases, 'entries' => $entries]);
    }

    /**
     * @param  array{repo: string, path: string, extract: string}  $where
     * @return array{0: string, 1: list<string>, 2: list<string>}|string [tag, keywords, annotations], or why not
     */
    private function read(array $where): array|string
    {
        $tags = GitHubTags::list($this->http, $where['repo'], $this->token);
        if (is_string($tags)) {
            return $tags;
        }
        $tag = GitHubTags::newestRelease($tags);
        if ($tag === null) {
            return 'it has no release tag';
        }
        $url = "https://raw.githubusercontent.com/{$where['repo']}/{$tag}/{$where['path']}";
        $response = $this->http->get($url, ['Accept' => 'text/plain']);
        if (! $response->ok()) {
            return "{$where['path']} at {$tag} returned HTTP {$response->status}";
        }
        [$keywords, $annotations] = match ($where['extract']) {
            self::EXTRACT_TS_INTERFACE => [self::tsInterface($response->body), []],
            self::EXTRACT_PHP_CASES => [self::phpCases($response->body), self::phpAnnotations($response->body)],
            default => [[], []],
        };
        if ($keywords === []) {
            return "no keywords found in {$where['path']} at {$tag} with the {$where['extract']} extractor";
        }

        return [$tag, $keywords, array_values(array_diff($annotations, $keywords))];
    }

    /** @return list<string> */
    public static function tsInterface(string $source): array
    {
        if (preg_match('/export\s+interface\s+SchemaObject\s*\{(.*?)\n\}/s', $source, $body) !== 1) {
            return [];
        }
        preg_match_all('/^\s*[\'"]?(\$?[A-Za-z][\w-]*)[\'"]?\??\s*:/m', $body[1], $names);

        return self::sorted($names[1]);
    }

    /** @return list<string> */
    public static function phpCases(string $source): array
    {
        preg_match_all('/\bcase\s+\'(\$?[A-Za-z][\w-]*)\'\s*:/', $source, $names);

        return self::sorted($names[1]);
    }

    /** @return list<string> */
    public static function phpAnnotations(string $source): array
    {
        if (preg_match('/const\s+ANNOTATIONS\s*=\s*\[(.*?)\]/s', $source, $list) !== 1) {
            return [];
        }
        preg_match_all('/\'(\$?[A-Za-z][\w-]*)\'/', $list[1], $names);

        return self::sorted($names[1]);
    }

    /**
     * @param  array<string>  $names
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }
}
