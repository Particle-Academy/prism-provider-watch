<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Dispatch;

use Prism\ProviderWatch\Diff\Change;
use Prism\ProviderWatch\Diff\DriftReport;
use Prism\ProviderWatch\Mcp\McpClient;

/**
 * DMs the drift straight to an agent's AgentInbox.
 *
 * ## Why this exists, which is the whole point of the watcher
 *
 * Detection was never the problem. `gpt-6-astra` and its new `steering`
 * parameter were caught on 2026-09-04 and written into that day's report,
 * correctly, with no human or agent noticing for five days. Three more drift
 * issues — #36, #37, #38 — sat open and unread across three consecutive days,
 * and one of them contained a provider adding `503` to every inference
 * endpoint while Prism's OpenAI handler mapped only `529`.
 *
 * **A watcher whose output nobody reads is a watcher that is not running.** A
 * file on disk and an issue in a tracker are both places you have to remember
 * to look. An inbox message arrives.
 *
 * ## It leads with the contract, not the count
 *
 * The message opens with {@see DriftReport::headline()} and lists only
 * {@see DriftReport::notable()} — the changes that can break something we
 * ship. The catalogue churn is counted, not enumerated, because a notice that
 * reads "10 change(s)" where eight are free-tier models appearing at an
 * aggregator is how a reader learns to skip the ninth.
 *
 * ## Failure is a note, never a crash
 *
 * Same contract as every other dispatcher here. A watcher that dies because it
 * could not send a message has turned a notification problem into a detection
 * outage, which is strictly worse than the thing it was reporting.
 */
final class AgentInboxDispatcher implements Dispatcher
{
    public function __construct(
        private readonly McpClient $mcp,
        /** AgentInbox agent id to DM. Empty means nobody is configured. */
        private readonly string $recipient = '',
        private readonly bool $enabled = false,
    ) {}

    /** @return list<string> */
    public function dispatch(DriftReport $report): array
    {
        if (! $this->enabled) {
            return ['AgentInbox: disabled (pass --inbox or set PRISM_WATCH_INBOX=1 to enable).'];
        }

        // NO DRIFT, NO MESSAGE. A daily "nothing changed" is how an inbox
        // becomes something to filter, and the whole reason this dispatcher
        // exists is that filtered notices are unread notices.
        if (! $report->hasDrift()) {
            return [];
        }

        if ($this->recipient === '') {
            return ['AgentInbox: skipped — no recipient (set PRISM_WATCH_INBOX_AGENT to an agent id).'];
        }

        $result = $this->mcp->call('genie', 'agentinbox', [
            'action' => 'send',
            'to' => $this->recipient,
            'text' => $this->message($report),
            // INTERRUPT ONLY WHEN THE CONTRACT MOVED. Glowing a terminal for
            // catalogue churn spends the one signal that makes an interrupt
            // worth anything.
            'interrupt' => $report->notable() !== [],
        ]);

        return $result->ok
            ? ["AgentInbox: drift sent to {$this->recipient}."]
            : ["AgentInbox: {$result->error}"];
    }

    private function message(DriftReport $report): string
    {
        $date = substr($report->generatedAt, 0, 10);
        $notable = $report->notable();
        $routine = count($report->changes) - count($notable);

        $lines = [
            "Provider drift {$date} — {$report->headline()}.",
            '',
        ];

        if ($notable === []) {
            $lines[] = "No API contract changed. {$routine} catalogue change(s) — models appearing or";
            $lines[] = 'disappearing at a provider — are in the report and need no action.';
        } else {
            $lines[] = 'CONTRACT CHANGES — these are the ones that can break something we ship:';
            $lines[] = '';
            foreach ($notable as $change) {
                $lines[] = sprintf('- [%s] %s: %s', $change->provider, $change->source, $change->subject);
            }

            if ($routine > 0) {
                $lines[] = '';
                $lines[] = "Plus {$routine} catalogue change(s), listed in the report and needing no action.";
            }
        }

        $lines[] = '';
        $lines[] = 'Full report: repos/prism-provider-watch/reports/latest.md';
        $lines[] = 'Check each contract change against repos/prism/src before deciding it is nothing.';

        return implode("\n", $lines);
    }
}
