<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Diff;

/**
 * The human- and agent-facing artifact of a watch run: every change grouped by
 * provider, each affected provider annotated with the prism source paths a
 * maintainer (or a phase-2 coding agent) would need to touch to catch up.
 */
final readonly class DriftReport
{
    /**
     * @param  list<Change>  $changes
     * @param  array<string, list<string>>  $prismHints  target key => source paths in repos/prism
     * @param  array<string, string>  $labels  target key => display label. Headings fall back to
     *                                         the key, which reads fine for `openai` and badly for
     *                                         a non-provider target like `mcp`.
     */
    public function __construct(
        public array $changes,
        public array $prismHints = [],
        public string $generatedAt = '',
        public array $labels = [],
    ) {}

    public function hasDrift(): bool
    {
        return $this->changes !== [];
    }

    /** @return array<string, list<Change>> */
    public function byProvider(): array
    {
        $grouped = [];
        foreach ($this->changes as $change) {
            $grouped[$change->provider][] = $change;
        }
        ksort($grouped);

        return $grouped;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt,
            'has_drift' => $this->hasDrift(),
            'change_count' => count($this->changes),
            'changes' => array_map(static fn (Change $c): array => $c->toArray(), $this->changes),
        ];
    }

    public function toMarkdown(): string
    {
        if (! $this->hasDrift()) {
            return "# Provider watch — no drift\n\n_Checked at {$this->generatedAt}. All watched providers match the committed baseline._\n";
        }

        $lines = [
            '# Provider watch — drift detected',
            '',
            "_Generated at {$this->generatedAt}. ".count($this->changes).' change(s) across '.count($this->byProvider()).' watch target(s)._',
            '',
        ];

        foreach ($this->byProvider() as $provider => $changes) {
            $lines[] = '## '.($this->labels[$provider] ?? $provider);
            $lines[] = '';
            foreach ($changes as $change) {
                $detail = $change->detail !== '' ? " — {$change->detail}" : '';
                $lines[] = "- `{$change->type->symbol()}` **{$change->source}**: {$change->subject}{$detail}";
            }
            $hints = $this->prismHints[$provider] ?? [];
            if ($hints !== []) {
                $lines[] = '';
                $lines[] = '  _prism surfaces to review: '.implode(', ', array_map(static fn (string $h): string => "`{$h}`", $hints)).'_';
            }
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }
}
