<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Dispatch;

use Prism\ProviderWatch\Diff\Change;
use Prism\ProviderWatch\Diff\DriftReport;
use Prism\ProviderWatch\Mcp\McpClient;

/**
 * Files a Tynn backlog story per drifted provider — the actionable "absorb this
 * change into prism" work item, annotated with the prism source files to touch.
 * The "Tynn" half of the hybrid's last mile. Opt-in via --tynn; no drift → no
 * stories; any MCP failure degrades to a note.
 *
 * Stories attach to a version: the configured PRISM_WATCH_TYNN_VERSION, else the
 * project's currently active version (resolved live via Tynn `next`).
 */
final class TynnStoryDispatcher implements Dispatcher
{
    public function __construct(
        private readonly McpClient $mcp,
        private readonly bool $enabled = false,
        private readonly ?string $versionId = null,
    ) {}

    /** @return list<string> */
    public function dispatch(DriftReport $report): array
    {
        if (! $this->enabled) {
            return ['Tynn story: disabled (pass --tynn or set PRISM_WATCH_TYNN=1).'];
        }

        if (! $report->hasDrift()) {
            return [];
        }

        $versionId = $this->versionId ?? $this->resolveActiveVersion();
        if ($versionId === null || $versionId === '') {
            return ['Tynn story: no target version (set PRISM_WATCH_TYNN_VERSION or check Tynn access).'];
        }

        $lines = [];
        foreach ($report->byProvider() as $provider => $changes) {
            $result = $this->mcp->call('tynn', 'create', [
                'a' => 'story',
                'title' => "Provider drift: {$provider} — ".count($changes).' change(s) to absorb into prism',
                'because' => $this->body($provider, $changes, $report->prismHints[$provider] ?? []),
                'on' => ['version_id' => $versionId],
            ]);

            $lines[] = $result->ok
                ? "Tynn story filed for {$provider}."
                : "Tynn story ({$provider}): {$result->error}";
        }

        return $lines;
    }

    private function resolveActiveVersion(): ?string
    {
        $result = $this->mcp->call('tynn', 'next', ['fields' => 'active_version.id']);
        if (! $result->ok) {
            return null;
        }

        $decoded = json_decode($result->text, true);

        return is_array($decoded) ? ($decoded['active_version']['id'] ?? null) : null;
    }

    /**
     * @param  list<Change>  $changes
     * @param  list<string>  $prismHints
     */
    private function body(string $provider, array $changes, array $prismHints): string
    {
        $lines = ["Provider **{$provider}** changed — reconcile prism to match:", ''];
        foreach ($changes as $change) {
            $detail = $change->detail !== '' ? " — {$change->detail}" : '';
            $lines[] = "- `{$change->type->symbol()}` {$change->source}: {$change->subject}{$detail}";
        }
        if ($prismHints !== []) {
            $lines[] = '';
            $lines[] = 'prism surfaces to review: '.implode(', ', $prismHints);
        }

        return implode("\n", $lines);
    }
}
