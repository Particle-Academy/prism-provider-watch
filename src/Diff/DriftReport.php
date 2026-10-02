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
        /**
         * How many sources had real data on BOTH sides, so the comparison meant
         * something. Counted with {@see Differ::isComparable()} — the same
         * predicate the differ gates on, deliberately shared so this number
         * cannot drift from the work it describes.
         *
         * DEFAULTS TO ZERO, WHICH READS AS "NOTHING WAS COMPARED". That is the
         * fail-closed direction and it is the point: an absent count must not
         * render as the reassuring message. A nullable "unknown" that fell back
         * to "no drift" would reopen exactly the hole this closes.
         */
        public int $comparedSources = 0,
        /** Sources skipped, failed, or seen for the first time. */
        public int $uncomparedSources = 0,
    ) {}

    /**
     * Whether this run actually compared anything.
     *
     * WHY THIS IS A SEPARATE QUESTION FROM hasDrift(). An empty change list has
     * two causes that look identical: upstream did not move, or nothing was
     * polled successfully. Before this existed the report asserted the first
     * one unconditionally -- "All watched providers match the committed
     * baseline" -- so a total network failure produced a confident no-drift
     * report, a zero exit, and a baseline overwritten with the failures.
     *
     * Silence is not agreement.
     */
    public function compared(): bool
    {
        return $this->comparedSources > 0;
    }

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
            $this->comparedSources,
            $this->uncomparedSources,
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
            // Without these an archived no-drift report is unfalsifiable: a run
            // that compared 41 sources and one that compared none both wrote
            // has_drift:false and change_count:0.
            'compared' => $this->compared(),
            'compared_sources' => $this->comparedSources,
            'uncompared_sources' => $this->uncomparedSources,
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
        if (! $this->hasDrift() && ! $this->compared()) {
            return "# Provider watch — NOTHING WAS COMPARED\n\n"
                ."_Checked at {$this->generatedAt}. {$this->uncomparedSources} source(s) polled, "
                ."0 compared against a baseline._\n\n"
                ."> **This is not a no-drift result. This run cannot say whether anything changed.**\n"
                ."> Every source was skipped, failed, or seen for the first time, so there was\n"
                ."> nothing to compare. Read the operational notes for the reason — a network\n"
                ."> failure, an expired credential and a renamed upstream feed all land here.\n"
                ."> The committed baseline was left intact rather than overwritten with the\n"
                ."> failures, so the next successful run reports the real change instead of\n"
                ."> silently adopting it.\n";
        }

        if (! $this->hasDrift()) {
            return "# Provider watch — no drift\n\n"
                ."_Checked at {$this->generatedAt}. {$this->comparedSources} source(s) compared "
                ."against the committed baseline, {$this->uncomparedSources} not compared. "
                ."Every compared source matches._\n";
        }

        $lines = [
            '# Provider watch — drift detected',
            '',
            "_Generated at {$this->generatedAt}. ".count($this->changes).' change(s) across '.count($this->byProvider()).' watch target(s)._',
            '',
            // ACT ON THIS WITHOUT WAITING FOR THE OPERATOR. Seven of these
            // reports accumulated unread between 2026-09-25 and 2026-10-01 --
            // 57 changes -- while every agent that saw one treated it as
            // something to raise rather than something to do. One of them had
            // already named the cause of a bug we went on to ship and fix
            // reactively: "Allow embedding vector to be base64-encoded string
            // or float array", 2026-09-28, three days before a TypeError in
            // exactly that path.
            //
            // The instruction is in the issue body rather than only in an
            // agent's memory because the reader is whoever opens the issue,
            // and that is not reliably an agent that has read ours.
            '> **Triage this now. Do not wait for the operator.**',
            '>',
            '> Keeping Prism aligned with the provider APIs is the **#1 priority of',
            '> this workspace. All client demands are secondary.** A contract that has',
            '> moved underneath us invalidates work done above it, so this outranks',
            '> ports, features and benchmarks — and it does not need approval first.',
            '>',
            '> Most lines here are an upstream commit or release, not a break in our',
            '> code. The triage is the work: for each one, decide whether it touches a',
            '> capability we ship, then CHECK THE CLAIM AGAINST THE SOURCE rather than',
            '> the title. A removed request option, a deprecated model named in our',
            '> docs, or a response field that can now hold a second type are the',
            '> shapes that actually reach a consumer.',
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
