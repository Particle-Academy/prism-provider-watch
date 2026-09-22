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

        // NOTHING IS FILED FOR A DAY THAT NEEDS NO WORK. An issue is a request
        // for someone to do something; a catalogue that moved at an aggregator
        // we do not ship is not one. Filing it anyway put 22 reports-on-nothing
        // into the product intake against a single one that asked for a change,
        // and a channel that is 22/23 noise stops being read -- including on
        // the day it matters.
        //
        // The catalogue is still diffed, archived and reported. It just no
        // longer opens an issue. A contract change -- a request or response
        // shape moving, the one class of drift that can break a running
        // application -- still does, catalogue noise beside it or not.
        if ($report->notable() === []) {
            return [sprintf('GitHub issue: none — %s, so there is nothing to act on.', $report->headline())];
        }

        if (! $this->enabled) {
            return ['GitHub issue: disabled (pass --issues or set PRISM_WATCH_OPEN_ISSUES=1 to enable).'];
        }

        if (! $this->cli->available()) {
            return ['GitHub issue: skipped — `gh` CLI not available on PATH.'];
        }

        $date = $this->dateFrom($report->generatedAt);

        // LEADS WITH THE CONTRACT, NOT THE COUNT. "Provider drift: 10 change(s)"
        // is a title a reader learns to skip, and correctly so when eight of the
        // ten are free-tier models churning at an aggregator. Then the ninth is
        // a provider adding 503 to every inference endpoint and it is skipped
        // with the rest — which is what happened, for a day, while Prism's
        // OpenAI handler mapped only 529.
        $title = sprintf('Provider drift: %s — %s', $report->headline(), $date);

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
