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
     * @param  array<string, string>  $categories  target key => category. Decides which section a
     *                                             target lands in and how its follow-up line reads.
     * @param  array<string, string>  $why  target key => why it is watched, for readers who did not
     *                                      add the target.
     */
    public function __construct(
        public array $changes,
        public array $prismHints = [],
        public string $generatedAt = '',
        public array $labels = [],
        public array $categories = [],
        public array $why = [],
        /**
         * What each dispatcher reported doing, one line each.
         *
         * EMPTY UNTIL THE DISPATCH HAS HAPPENED, which is why it is last and
         * defaulted — see {@see self::withDispatch()}.
         *
         * @var list<string>
         */
        public array $dispatch = [],
    ) {}

    /**
     * The same report, carrying what the dispatchers reported doing.
     *
     * WHY THIS EXISTS. The archive recorded what CHANGED and never what was
     * DONE about it, so "did anyone get told?" was unanswerable after the fact.
     * That is not hypothetical: gpt-6-astra was detected on 2026-09-04 and no
     * issue exists for that day, and nothing on disk can say whether the issue
     * failed, was skipped as a duplicate, or was never attempted.
     *
     * A watcher whose delivery cannot be audited is a watcher you have to trust
     * rather than check.
     *
     * @param  list<string>  $lines
     */
    public function withDispatch(array $lines): self
    {
        return new self(
            $this->changes,
            $this->prismHints,
            $this->generatedAt,
            $this->labels,
            $this->categories,
            $this->why,
            $lines,
        );
    }

    /**
     * Section order, and the question each section is asking.
     *
     * Providers come first because a provider change can break a running app;
     * competitors come last because they change what we should build, which is
     * urgent on a scale of weeks rather than minutes.
     *
     * @var array<string, array{title: string, lead: string}>
     */
    private const SECTIONS = [
        'provider' => [
            'title' => 'Providers',
            'lead' => 'What changed under an integration we ship.',
        ],
        'upstream' => [
            'title' => 'Upstream',
            'lead' => 'Community work on prism-php/prism that this fork exists to absorb.',
        ],
        'ecosystem' => [
            'title' => 'Ecosystem',
            'lead' => 'Protocol and platform moves that decide what integrating with an app means.',
        ],
        'competitor' => [
            'title' => 'Competitors',
            'lead' => 'What the field shipped. Read for direction, not for feature envy.',
        ],
    ];

    /**
     * Sources that describe an API CONTRACT rather than a catalogue.
     *
     * An `openapi-spec` change means the shape of a request or response moved,
     * which is the only class of drift that can break a running application.
     * `models-dev` is a catalogue: models appear and disappear constantly —
     * 78 of them across the archive against 35 contract changes — and almost
     * all of it is free-tier churn at an aggregator we do not ship.
     *
     * @var list<string>
     */
    private const CONTRACT_SOURCES = ['openapi-spec'];

    public function hasDrift(): bool
    {
        return $this->changes !== [];
    }

    /**
     * The changes that can plausibly break something we ship.
     *
     * WHY THIS IS NOT JUST `changes`. A report reading "10 change(s)" where
     * eight are free-tier models churning at an aggregator trains a reader to
     * skip it — and then the ninth, a provider adding a 503 to every inference
     * endpoint, is skipped with the rest. That is not hypothetical: it is
     * exactly what happened. Prism's OpenAI handler went on mapping only 529
     * for a day while the notice sat unread in a filed issue.
     *
     * So the noisy majority is still reported, still archived, and no longer
     * decides what the title says.
     *
     * @return list<Change>
     */
    public function notable(): array
    {
        return array_values(array_filter(
            $this->changes,
            fn (Change $c): bool => in_array($c->source, self::CONTRACT_SOURCES, true),
        ));
    }

    /**
     * One line that says whether this run is worth opening.
     */
    public function headline(): string
    {
        $notable = count($this->notable());
        $total = count($this->changes);

        if ($notable === 0) {
            return sprintf('%d catalogue change(s), no contract changes', $total);
        }

        return sprintf(
            '%d contract change(s)%s',
            $notable,
            $total > $notable ? sprintf(' (+%d catalogue)', $total - $notable) : '',
        );
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
            // What was actually done about it. Present even when empty, so an
            // archived report with no dispatch line is distinguishable from one
            // written before this field existed.
            'dispatch' => $this->dispatch,
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

        $grouped = $this->byProvider();

        foreach (self::SECTIONS as $category => $section) {
            $inSection = array_filter(
                $grouped,
                fn (string $key): bool => ($this->categories[$key] ?? 'provider') === $category,
                ARRAY_FILTER_USE_KEY,
            );

            if ($inSection === []) {
                continue;
            }

            $lines[] = '# '.$section['title'];
            $lines[] = '';
            $lines[] = '_'.$section['lead'].'_';
            $lines[] = '';

            foreach ($inSection as $provider => $changes) {
                $lines[] = '## '.($this->labels[$provider] ?? $provider);

                $why = $this->why[$provider] ?? '';
                if ($why !== '') {
                    $lines[] = '';
                    $lines[] = "_{$why}_";
                }

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
        }

        return implode("\n", $lines)."\n";
    }
}
