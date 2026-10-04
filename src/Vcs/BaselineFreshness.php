<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Vcs;

use DateTimeImmutable;

/**
 * How current the COMMITTED baseline is.
 *
 * WHY THIS EXISTS. AGENTS.md states the rule the whole differ depends on: "a
 * baseline that is regenerated rather than reviewed reports no drift forever --
 * the diff is against yesterday's fetch instead of against what was last
 * understood. When a run reports drift, the baseline advances AFTER someone has
 * read what changed, not as part of the run."
 *
 * Nothing enforced it. The run rewrites state/ on disk every time, and the only
 * thing that marks a baseline as reviewed is a human committing it -- so the
 * rule was a convention, and on 2026-10-04 it was measured nineteen days
 * unobserved: last baseline commit 2026-09-13, nine of twelve files dirty. The
 * README's claim that "their git diff IS the provider changelog" had quietly
 * stopped being true, and no check anywhere could say so.
 *
 * This makes it a measurement. It does not commit anything -- reconciliation
 * stays a human review step, which is the point of the rule -- it only makes
 * the gap between "what the differ compares against" and "what was last
 * understood" impossible to hold without being told.
 */
final readonly class BaselineFreshness
{
    private function __construct(
        /** Null when git cannot answer: no binary, not a checkout, no history. */
        public ?string $committedAt,
        /** Null when it cannot be established -- never 0 as a stand-in. */
        public ?int $dirtyFiles,
        public ?int $daysSinceCommit,
    ) {}

    public static function inspect(GitCli $git, string $stateDir, DateTimeImmutable $now): self
    {
        if (! $git->available()) {
            return new self(null, null, null);
        }

        $committedAt = $git->lastCommitIso($stateDir);
        $days = null;

        if ($committedAt !== null) {
            $committed = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $committedAt);
            if ($committed !== false) {
                // Whole days, floored: a baseline committed 25 hours ago is one
                // day stale, not two, so the threshold is not tripped by the
                // clock drifting past midnight.
                $days = (int) floor(($now->getTimestamp() - $committed->getTimestamp()) / 86400);
            }
        }

        return new self($committedAt, $git->dirtyFileCount($stateDir), $days);
    }

    /** Whether git could answer at all. */
    public function determinable(): bool
    {
        return $this->committedAt !== null || $this->dirtyFiles !== null;
    }

    /**
     * Uncommitted baseline changes older than the threshold.
     *
     * Both conditions are required. A dirty tree on the same day is the normal
     * state halfway through a watch-and-review cycle, and failing on it would
     * make the check fire every day -- which is how a check becomes wallpaper
     * and stops being read. The fix for a true positive here is a single
     * commit, so a signal that persists until someone makes it is a to-do
     * rather than noise.
     */
    public function isStale(int $thresholdDays): bool
    {
        return ($this->dirtyFiles ?? 0) > 0
            && $this->daysSinceCommit !== null
            && $this->daysSinceCommit >= $thresholdDays;
    }

    /** One line for a report or a console, honest about not knowing. */
    public function describe(): string
    {
        if (! $this->determinable()) {
            return 'Baseline freshness unknown: this is not a git checkout, so nothing can say '
                .'whether the committed baseline matches what the differ compared against.';
        }

        $dirty = $this->dirtyFiles ?? 0;
        $committed = $this->committedAt ?? 'never';
        $age = $this->daysSinceCommit === null ? 'unknown' : $this->daysSinceCommit.' day(s) ago';

        if ($dirty === 0) {
            return "Committed baseline is current: last committed {$committed} ({$age}), no uncommitted state files.";
        }

        return "Committed baseline is BEHIND the working tree: {$dirty} state file(s) uncommitted, "
            ."last committed {$committed} ({$age}). Until these are reviewed and committed, the differ "
            .'compares against yesterday\'s fetch rather than against what was last understood, and '
            .'the git history stops being the provider changelog.';
    }
}
