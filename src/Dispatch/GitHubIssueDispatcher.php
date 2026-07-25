<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Dispatch;

use Prism\ProviderWatch\Diff\DriftReport;
use Prism\ProviderWatch\GitHub\GitHubCli;

/**
 * Opens a `provider-watch` issue on prism when drift is found — the "issue" half
 * of the phase-1 autonomy contract. Idempotent per run: it skips creation if an
 * open issue with the identical title already exists, so re-runs on the same day
 * don't spam. No drift → no issue. `gh` missing/unauthenticated → a note, never
 * a crash.
 */
final class GitHubIssueDispatcher implements Dispatcher
{
    public function __construct(
        private readonly GitHubCli $cli,
        private readonly string $repo,
        private readonly bool $enabled = false,
        private readonly string $label = 'provider-watch',
    ) {}

    /** @return list<string> */
    public function dispatch(DriftReport $report): array
    {
        if (! $report->hasDrift()) {
            return [];
        }

        if (! $this->enabled) {
            return ['GitHub issue: disabled (pass --issues or set PRISM_WATCH_OPEN_ISSUES=1 to enable).'];
        }

        if (! $this->cli->available()) {
            return ['GitHub issue: skipped — `gh` CLI not available on PATH.'];
        }

        $date = $this->dateFrom($report->generatedAt);
        $title = sprintf('Provider drift: %d change(s) — %s', count($report->changes), $date);

        if ($this->cli->findOpenIssueByTitle($this->repo, $title) !== null) {
            return ["GitHub issue: an open issue titled \"{$title}\" already exists — skipped."];
        }

        $url = $this->cli->createIssue($this->repo, $title, $report->toMarkdown(), [$this->label]);

        return $url !== ''
            ? ["GitHub issue opened: {$url}"]
            : ['GitHub issue: `gh issue create` returned no URL (check auth/labels).'];
    }

    private function dateFrom(string $generatedAt): string
    {
        // generatedAt is an ISO-8601 string; take the date portion for a stable title.
        return substr($generatedAt, 0, 10) ?: $generatedAt;
    }
}
