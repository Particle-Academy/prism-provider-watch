<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Dispatch;

use Prism\ProviderWatch\Diff\DriftReport;
use RuntimeException;

/**
 * Always-on sink: writes the drift report as markdown + JSON to the report dir.
 * Point the report dir at the envelope's .ai/knowledge/provider-watch/ and the
 * report becomes a durable, agent-readable knowledge entry — satisfying the
 * "knowledge entry" half of the phase-1 autonomy contract with zero network.
 *
 * A drift report is ALSO archived under a timestamp. `latest.*` alone is
 * destroyed by the next run, and since a run re-baselines as it reports, the
 * following run finds no drift and overwrites the only record of what changed
 * with "no drift" — which is exactly what happened once a daily cron started
 * firing. The archive is the history; `latest.*` is just the pointer.
 */
final class FileDriftDispatcher implements Dispatcher
{
    public function __construct(private readonly string $reportDir) {}

    /** @return list<string> */
    public function dispatch(DriftReport $report): array
    {
        if (! is_dir($this->reportDir) && ! @mkdir($this->reportDir, 0o755, true) && ! is_dir($this->reportDir)) {
            throw new RuntimeException("Cannot create report dir: {$this->reportDir}");
        }

        $base = rtrim($this->reportDir, '/\\').DIRECTORY_SEPARATOR;
        $markdown = $report->toMarkdown();
        $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

        file_put_contents($base.'latest.md', $markdown);
        file_put_contents($base.'latest.json', $json);

        $written = ["Wrote drift report to {$base}latest.md"];

        // Only drift is worth keeping — archiving every clean run would bury
        // the reports that matter under a file a day saying nothing happened.
        if ($report->hasDrift()) {
            $archive = $base.'archive'.DIRECTORY_SEPARATOR;

            if (! is_dir($archive) && ! @mkdir($archive, 0o755, true) && ! is_dir($archive)) {
                throw new RuntimeException("Cannot create archive dir: {$archive}");
            }

            $stamp = gmdate('Y-m-d\THis\Z');
            file_put_contents($archive.$stamp.'.md', $markdown);
            file_put_contents($archive.$stamp.'.json', $json);

            $written[] = "Archived drift to {$archive}{$stamp}.md";
        }

        return $written;
    }
}
