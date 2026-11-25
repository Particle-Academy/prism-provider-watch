<?php

declare(strict_types=1);

use Prism\ProviderWatch\Diff\ChangeType;
use Prism\ProviderWatch\Diff\Differ;
use Prism\ProviderWatch\Diff\DriftReport;
use Prism\ProviderWatch\Http\Http;
use Prism\ProviderWatch\Http\HttpResponse;
use Prism\ProviderWatch\Providers\SourceFactory;
use Prism\ProviderWatch\Snapshot\Snapshot;
use Prism\ProviderWatch\Sources\PackageVersionsSource;
use Prism\ProviderWatch\Sources\SourceResult;
use Prism\ProviderWatch\Sources\ValidatorKeywordsSource;

/*
 * Fancy's fancy-schema is watched, not depended on: Prism owns its validators (Wish ruling 2026-11-25), but the
 * schemas Prism hands Fancy's agent-integrations must stay inside the keywords fancy-schema evaluates. These pin that
 * a new release on any channel, and a keyword added or dropped by either implementation, reach the report.
 */

/** Answers by URL; anything unmapped is a 404. */
final class FancyFakeHttp implements Http
{
    /** @var array<string, array<string, string>> */
    public array $headers = [];

    /** @param array<string, HttpResponse> $routes */
    public function __construct(private array $routes) {}

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->headers[$url] = $headers;

        return $this->routes[$url] ?? new HttpResponse(404, '');
    }

    public function post(string $url, string $body, array $headers = []): HttpResponse
    {
        return new HttpResponse(405, '');
    }
}

const NPM_URL = 'https://registry.npmjs.org/@particle-academy%2ffancy-schema';
const PACKAGIST_URL = 'https://repo.packagist.org/p2/particle-academy/fancy-schema.json';
const JS_TAGS = 'https://api.github.com/repos/Particle-Academy/fancy-schema/tags?per_page=100';
const PHP_TAGS = 'https://api.github.com/repos/Particle-Academy/fancy-schema-php/tags?per_page=100';

/** @param array<mixed> $value */
function fancyJson(array $value, int $status = 200): HttpResponse
{
    return new HttpResponse($status, json_encode($value, JSON_THROW_ON_ERROR));
}

/** @param list<string> $names */
function fancyTags(array $names): HttpResponse
{
    return fancyJson(array_map(fn (string $n): array => ['name' => $n], $names));
}

function versionsSource(Http $http, ?string $token = null): PackageVersionsSource
{
    return new PackageVersionsSource($http, 'versions', 'fancy-schema releases', '@particle-academy/fancy-schema',
        'particle-academy/fancy-schema', ['Particle-Academy/fancy-schema-php', 'Particle-Academy/fancy-schema'], $token);
}

function keywordsSource(Http $http): ValidatorKeywordsSource
{
    return new ValidatorKeywordsSource($http, 'validator-keywords', 'fancy-schema keywords', [
        'fancy-schema-php' => ['repo' => 'Particle-Academy/fancy-schema-php', 'path' => 'src/Compiler.php', 'extract' => 'php-cases'],
        'fancy-schema' => ['repo' => 'Particle-Academy/fancy-schema', 'path' => 'src/index.ts', 'extract' => 'ts-interface'],
    ]);
}

/** @param list<string> $keys */
function tsSource(array $keys): string
{
    $lines = array_map(fn (string $k): string => "  {$k}?: unknown;", $keys);

    return "export type Schema = boolean | SchemaObject;\n\nexport interface SchemaObject {\n".implode("\n", $lines)
        ."\n  /** Annotations and vocabulary we do not implement are ignored, never failed. */\n  [key: string]: unknown;\n}\n\n"
        ."export interface ValidationError {\n  instancePath: string;\n  keyword: string;\n}\n";
}

/** @param list<string> $cases */
function phpSource(array $cases): string
{
    $body = implode("\n", array_map(fn (string $c): string => "                case '{$c}':\n                    break;", $cases));

    return "<?php\nfinal class Compiler\n{\n    private const ANNOTATIONS = ['\$comment', 'title', 'description'];\n\n"
        ."    private const TYPES = ['string', 'number'];\n\n    private function walk(): void\n    {\n            switch (\$key) {\n{$body}\n"
        ."                default:\n                    \$this->problem('unsupported_keyword', \$at, 'x');\n            }\n    }\n}\n";
}

/** @return list<string> */
function fancyIds(SourceResult $result): array
{
    return array_column($result->data['entries'], 'id');
}

/** @param array<string, mixed> $data */
function fancySnapshot(string $id, array $data): Snapshot
{
    return new Snapshot('fancy-schema', [$id => SourceResult::ok($id, $data)]);
}

it('lists every version on every channel, and an npm name not yet published as an entry of its own', function () {
    $http = new FancyFakeHttp([
        PACKAGIST_URL => fancyJson(['packages' => ['particle-academy/fancy-schema' => [['version' => 'v0.1.0']]]]),
        JS_TAGS => fancyTags(['v0.2.1', 'v0.2.0']),
        PHP_TAGS => fancyTags(['v0.1.0']),
    ]);

    $result = versionsSource($http)->poll();

    expect($result->ok)->toBeTrue()
        ->and($result->data['report_removed'])->toBeTrue()
        ->and(fancyIds($result))->toBe([
            'packagist:particle-academy/fancy-schema@v0.1.0',
            'tag:Particle-Academy/fancy-schema-php@v0.1.0',
            'tag:Particle-Academy/fancy-schema@v0.2.0',
            'tag:Particle-Academy/fancy-schema@v0.2.1',
            'unpublished:npm:@particle-academy/fancy-schema',
        ]);
});

it('reports the first npm publish as the unpublished entry going and the version arriving', function () {
    $routes = [
        PACKAGIST_URL => fancyJson(['packages' => ['particle-academy/fancy-schema' => [['version' => 'v0.1.0']]]]),
        JS_TAGS => fancyTags(['v0.2.1']),
        PHP_TAGS => fancyTags(['v0.1.0']),
    ];
    $before = versionsSource(new FancyFakeHttp($routes))->poll();
    $after = versionsSource(new FancyFakeHttp([...$routes, NPM_URL => fancyJson(['versions' => ['0.3.0' => [], '0.2.1' => []]])]))->poll();

    $changes = (new Differ)->diff(fancySnapshot('versions', $before->data), fancySnapshot('versions', $after->data));
    $byType = [];
    foreach ($changes as $change) {
        $byType[$change->type->value][] = $change->subject;
    }

    expect($byType['added'])->toBe([
        '@particle-academy/fancy-schema@0.2.1 published on npm',
        '@particle-academy/fancy-schema@0.3.0 published on npm',
    ])->and($byType['removed'])->toBe(['@particle-academy/fancy-schema is not published on npm']);
});

it('keeps an unreadable channel as an entry and still reads the rest', function () {
    $http = new FancyFakeHttp([
        NPM_URL => new HttpResponse(503, ''),
        PACKAGIST_URL => fancyJson(['packages' => ['particle-academy/fancy-schema' => [['version' => 'v0.1.0']]]]),
        JS_TAGS => fancyTags(['v0.2.1']),
        PHP_TAGS => new HttpResponse(403, '{"message":"rate limited"}'),
    ]);

    $result = versionsSource($http)->poll();

    expect($result->ok)->toBeTrue()
        ->and(fancyIds($result))->toContain('unreadable:npm:@particle-academy/fancy-schema')
        ->and(fancyIds($result))->toContain('unreadable:tag:Particle-Academy/fancy-schema-php')
        ->and(fancyIds($result))->toContain('tag:Particle-Academy/fancy-schema@v0.2.1');
});

it('fails the poll only when no channel could be read', function () {
    $result = versionsSource(new FancyFakeHttp([
        NPM_URL => new HttpResponse(500, ''),
        PACKAGIST_URL => new HttpResponse(500, ''),
        JS_TAGS => new HttpResponse(0, ''),
        PHP_TAGS => new HttpResponse(0, ''),
    ]))->poll();

    expect($result->ok)->toBeFalse()
        ->and($result->note)->toContain('No channel could be read');
});

it('sends a token to the GitHub API only, never to a registry', function () {
    $http = new FancyFakeHttp([JS_TAGS => fancyTags(['v0.2.1'])]);

    versionsSource($http, 'fixture-token')->poll();

    expect($http->headers[JS_TAGS]['Authorization'])->toBe('Bearer fixture-token')
        ->and($http->headers[NPM_URL])->not->toHaveKey('Authorization')
        ->and($http->headers[PACKAGIST_URL])->not->toHaveKey('Authorization');
});

it('reads each implementation\'s keywords at its newest release tag', function () {
    $http = new FancyFakeHttp([
        // v0.10.0 is newer than v0.9.0 and v0.2.1: compared as versions, not as strings. The prerelease is ignored.
        JS_TAGS => fancyTags(['v0.2.1', 'v0.9.0', 'v0.10.0', 'v0.11.0-beta.1']),
        PHP_TAGS => fancyTags(['v0.1.0']),
        'https://raw.githubusercontent.com/Particle-Academy/fancy-schema/v0.10.0/src/index.ts' => new HttpResponse(200, tsSource(['$ref', 'type', 'anyOf', 'if'])),
        'https://raw.githubusercontent.com/Particle-Academy/fancy-schema-php/v0.1.0/src/Compiler.php' => new HttpResponse(200, phpSource(['$ref', 'type', 'oneOf', 'type'])),
    ]);

    $result = keywordsSource($http)->poll();

    expect($result->ok)->toBeTrue()
        ->and($result->data['releases'])->toBe(['fancy-schema' => 'v0.10.0', 'fancy-schema-php' => 'v0.1.0'])
        ->and(fancyIds($result))->toBe([
            'annotation:fancy-schema-php:$comment',
            'annotation:fancy-schema-php:description',
            'annotation:fancy-schema-php:title',
            'keyword:fancy-schema-php:$ref',
            'keyword:fancy-schema-php:oneOf',
            'keyword:fancy-schema-php:type',
            'keyword:fancy-schema:$ref',
            'keyword:fancy-schema:anyOf',
            'keyword:fancy-schema:if',
            'keyword:fancy-schema:type',
        ]);
});

it('reports a keyword an implementation drops, and one it adds, as contract drift', function () {
    $routes = [
        JS_TAGS => fancyTags(['v0.2.1']),
        PHP_TAGS => fancyTags(['v0.1.0']),
        'https://raw.githubusercontent.com/Particle-Academy/fancy-schema/v0.2.1/src/index.ts' => new HttpResponse(200, tsSource(['type', 'anyOf'])),
        'https://raw.githubusercontent.com/Particle-Academy/fancy-schema-php/v0.1.0/src/Compiler.php' => new HttpResponse(200, phpSource(['type', 'oneOf'])),
    ];
    $before = keywordsSource(new FancyFakeHttp($routes))->poll();
    $after = keywordsSource(new FancyFakeHttp([
        ...$routes,
        PHP_TAGS => fancyTags(['v0.1.0', 'v0.2.0']),
        'https://raw.githubusercontent.com/Particle-Academy/fancy-schema-php/v0.2.0/src/Compiler.php' => new HttpResponse(200, phpSource(['type', 'anyOf'])),
    ]))->poll();

    $changes = (new Differ)->diff(fancySnapshot('validator-keywords', $before->data), fancySnapshot('validator-keywords', $after->data));
    $seen = array_map(fn ($c): string => $c->type->value.' '.$c->subject, $changes);

    expect($seen)->toBe([
        'added fancy-schema-php v0.2.0 evaluates anyOf',
        'removed fancy-schema-php v0.1.0 evaluates oneOf',
    ]);
    $report = new DriftReport($changes, [], '2026-11-25T00:00:00Z', ['fancy-schema' => 'Fancy fancy-schema'], ['fancy-schema' => 'ecosystem']);
    expect($report->notable())->toHaveCount(2);
});

it('reports an implementation it cannot read, or that yields no keywords, instead of going quiet', function () {
    $http = new FancyFakeHttp([
        JS_TAGS => fancyTags(['v0.2.1']),
        PHP_TAGS => fancyTags(['main-snapshot']),
        // A refactor moved the interface: the file is there but declares no SchemaObject.
        'https://raw.githubusercontent.com/Particle-Academy/fancy-schema/v0.2.1/src/index.ts' => new HttpResponse(200, "export type Schema = boolean;\n"),
    ]);

    $result = keywordsSource($http)->poll();

    // Neither could be read, so there is nothing to compare: the poll fails, and says why.
    expect($result->ok)->toBeFalse()
        ->and($result->note)->toContain('no keywords found in src/index.ts at v0.2.1')
        ->and($result->note)->toContain('it has no release tag');

    $http = new FancyFakeHttp([
        JS_TAGS => fancyTags(['v0.2.1']),
        PHP_TAGS => fancyTags(['v0.1.0']),
        'https://raw.githubusercontent.com/Particle-Academy/fancy-schema/v0.2.1/src/index.ts' => new HttpResponse(200, tsSource(['type'])),
    ]);
    $result = keywordsSource($http)->poll();

    expect($result->ok)->toBeTrue()
        ->and(fancyIds($result))->toContain('unreadable:fancy-schema-php')
        ->and(fancyIds($result))->toContain('keyword:fancy-schema:type');
});

it('extracts only the SchemaObject interface\'s own property names from TypeScript', function () {
    expect(ValidatorKeywordsSource::tsInterface(tsSource(['$ref', '$defs', 'type', 'const'])))
        ->toBe(['$defs', '$ref', 'const', 'type']);
});

it('still reports only added entries for a feed, whose entries are a rolling window', function () {
    $old = ['entries' => [['id' => 'a', 'title' => 'a'], ['id' => 'b', 'title' => 'b']]];
    $new = ['entries' => [['id' => 'b', 'title' => 'b'], ['id' => 'c', 'title' => 'c']]];

    $changes = (new Differ)->diff(fancySnapshot('sdk', $old), fancySnapshot('sdk', $new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->type)->toBe(ChangeType::Added)
        ->and($changes[0]->subject)->toBe('c');
});

it('is configured for both implementations and every channel, and builds without the network', function () {
    $config = require dirname(__DIR__, 2).'/config/providers.php';
    $target = $config['fancy-schema'];
    $sources = array_column($target['sources'], null, 'id');

    expect($target['category'])->toBe('ecosystem')
        ->and($sources['versions']['npm'])->toBe('@particle-academy/fancy-schema')
        ->and($sources['versions']['packagist'])->toBe('particle-academy/fancy-schema')
        ->and($sources['versions']['tags'])->toBe(['Particle-Academy/fancy-schema', 'Particle-Academy/fancy-schema-php'])
        ->and(array_keys($sources['validator-keywords']['implementations']))->toBe(['fancy-schema', 'fancy-schema-php']);

    $factory = new SourceFactory(new FancyFakeHttp([]), fn (string $name): false => false);
    expect($factory->make($sources['versions']))->toBeInstanceOf(PackageVersionsSource::class)
        ->and($factory->make($sources['validator-keywords']))->toBeInstanceOf(ValidatorKeywordsSource::class);
});
