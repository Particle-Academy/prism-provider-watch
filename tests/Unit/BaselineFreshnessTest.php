<?php

declare(strict_types=1);

use Prism\ProviderWatch\Vcs\BaselineFreshness;
use Prism\ProviderWatch\Vcs\GitCli;

/**
 * G-79: AGENTS.md says the baseline advances after someone reads what changed.
 * Nothing enforced it, and on 2026-10-04 it was measured nineteen days
 * unobserved. These pin the measurement that replaces the convention.
 */
final class FakeGit implements GitCli
{
    public function __construct(
        private readonly bool $available = true,
        private readonly ?string $iso = null,
        private readonly ?int $dirty = null,
    ) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function lastCommitIso(string $path): ?string
    {
        return $this->iso;
    }

    public function dirtyFileCount(string $path): ?int
    {
        return $this->dirty;
    }
}

function freshnessAt(string $now, GitCli $git): BaselineFreshness
{
    return BaselineFreshness::inspect($git, 'state', new DateTimeImmutable($now));
}

it('reports a current baseline when nothing is uncommitted', function (): void {
    $f = freshnessAt('2026-10-04T12:00:00Z', new FakeGit(iso: '2026-10-04T09:00:00+00:00', dirty: 0));

    expect($f->isStale(2))->toBeFalse()
        ->and($f->daysSinceCommit)->toBe(0)
        ->and($f->describe())->toContain('current');
});

it('reports the baseline as behind, and says what that costs, once it is stale', function (): void {
    // The measured case: nineteen days, nine files.
    $f = freshnessAt('2026-10-04T12:00:00Z', new FakeGit(iso: '2026-09-15T12:00:00+00:00', dirty: 9));

    expect($f->daysSinceCommit)->toBe(19)
        ->and($f->isStale(2))->toBeTrue()
        ->and($f->describe())->toContain('BEHIND')
        ->and($f->describe())->toContain('9 state file(s) uncommitted')
        // The consequence, not just the fact -- this is the sentence that makes
        // the finding actionable rather than a statistic.
        ->and($f->describe())->toContain("yesterday's fetch");
});

it('does not fire on a dirty tree within the threshold', function (): void {
    // Mid-cycle is the NORMAL state: polled today, awaiting review. Failing
    // here would make the check fire every day and become wallpaper.
    $f = freshnessAt('2026-10-04T12:00:00Z', new FakeGit(iso: '2026-10-04T06:00:00+00:00', dirty: 12));

    expect($f->isStale(2))->toBeFalse();
});

it('needs BOTH an uncommitted change and age to be stale', function (): void {
    $oldButClean = freshnessAt('2026-10-04T12:00:00Z', new FakeGit(iso: '2026-01-01T12:00:00+00:00', dirty: 0));

    // An old commit with nothing uncommitted is a quiet provider, not a lapse.
    expect($oldButClean->isStale(2))->toBeFalse();
});

it('counts whole days so a clock past midnight does not trip the threshold', function (): void {
    // 25 hours is one day, not two.
    $f = freshnessAt('2026-10-04T13:00:00Z', new FakeGit(iso: '2026-10-03T12:00:00+00:00', dirty: 3));

    expect($f->daysSinceCommit)->toBe(1)
        ->and($f->isStale(2))->toBeFalse();
});

it('says it does not know rather than saying the baseline is fine', function (): void {
    // A tarball install is a legitimate deployment and must not fail. It must
    // also not be told its baseline is current, because nothing knows that --
    // the absent case has to read as absent, not as reassurance.
    $f = freshnessAt('2026-10-04T12:00:00Z', new FakeGit(available: false));

    expect($f->determinable())->toBeFalse()
        ->and($f->isStale(2))->toBeFalse()
        ->and($f->describe())->toContain('unknown')
        ->and($f->describe())->not->toContain('current');
});

it('treats a path with no commit history as undeterminable, not as committed', function (): void {
    $f = freshnessAt('2026-10-04T12:00:00Z', new FakeGit(iso: null, dirty: 4));

    expect($f->daysSinceCommit)->toBeNull()
        // No history plus dirty files cannot be aged, so it must not be
        // reported stale on a guessed age either.
        ->and($f->isStale(2))->toBeFalse()
        ->and($f->describe())->toContain('never');
});
