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
        file_put_contents($base.'latest.md', $report->toMarkdown());
        file_put_contents(
            $base.'latest.json',
            json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
        );

        return ["Wrote drift report to {$base}latest.md"];
    }
}
