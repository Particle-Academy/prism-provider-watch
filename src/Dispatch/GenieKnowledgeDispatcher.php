<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Dispatch;

use Prism\ProviderWatch\Diff\DriftReport;
use Prism\ProviderWatch\Mcp\McpClient;

/**
 * Records a drift run as a node in Genie's workstation knowledge graph, so every
 * agent on the workstation can see the current provider state and what last
 * changed. The "Genie" half of the hybrid's last mile. Opt-in via --genie; no
 * drift → nothing recorded; any MCP failure degrades to a note.
 */
final class GenieKnowledgeDispatcher implements Dispatcher
{
    public function __construct(
        private readonly McpClient $mcp,
        private readonly bool $enabled = false,
    ) {}

    /** @return list<string> */
    public function dispatch(DriftReport $report): array
    {
        if (! $this->enabled) {
            return ['Genie knowledge: disabled (pass --genie or set PRISM_WATCH_GENIE=1).'];
        }

        if (! $report->hasDrift()) {
            return [];
        }

        $date = substr($report->generatedAt, 0, 10);
        $result = $this->mcp->call('genie', 'knowledge', [
            'action' => 'add',
            'title' => "Provider drift {$date} — ".count($report->changes).' change(s)',
            'body' => $report->toMarkdown(),
            'tags' => ['provider-watch', 'provider-drift', 'prism'],
        ]);

        return $result->ok
            ? ['Genie knowledge node recorded.']
            : ["Genie knowledge: {$result->error}"];
    }
}
