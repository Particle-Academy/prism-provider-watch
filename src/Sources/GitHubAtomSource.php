<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Sources;

use Prism\ProviderWatch\Http\Http;
use SimpleXMLElement;
use Throwable;

/**
 * The closest thing to a real "subscribe" that providers actually offer: the
 * public, no-auth Atom feed on their spec / SDK GitHub repos. Point it at
 *   https://github.com/<owner>/<repo>/releases.atom
 *   https://github.com/<owner>/<repo>/commits/<branch>.atom
 *   https://github.com/<owner>/<repo>/tags.atom
 * and each new <entry> is a fresh release / commit / tag to diff against.
 */
final class GitHubAtomSource implements Source
{
    public function __construct(
        private readonly Http $http,
        private readonly string $id,
        private readonly string $label,
        private readonly string $url,
        private readonly int $max = 15,
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
        $response = $this->http->get($this->url, ['Accept' => 'application/atom+xml']);

        if (! $response->ok()) {
            return SourceResult::failed($this->id, "GET {$this->url} returned HTTP {$response->status}.");
        }

        try {
            // LIBXML_NONET: never let the parser fetch external resources (DTDs,
            // entities) — a hostile or MITM'd feed can't turn XML parsing into an
            // outbound request. Entity substitution stays off (no LIBXML_NOENT).
            $xml = new SimpleXMLElement($response->body, LIBXML_NONET);
        } catch (Throwable $e) {
            return SourceResult::failed($this->id, "Malformed Atom from {$this->url}.");
        }

        $entries = [];
        foreach ($xml->entry as $entry) {
            $entries[] = [
                'id' => trim((string) $entry->id),
                'title' => trim((string) $entry->title),
            ];
            if (count($entries) >= $this->max) {
                break;
            }
        }

        return SourceResult::ok($this->id, [
            // Feeds are newest-first; keep that order for a stable, diff-friendly snapshot.
            'latest' => $entries[0]['title'] ?? '',
            'entries' => $entries,
        ]);
    }
}
