<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Vcs;

/**
 * The little of git this package needs to know whether its committed baseline
 * is current.
 *
 * An interface rather than a concrete call because the thing it reports on --
 * whether somebody committed the reviewed baseline -- is otherwise only
 * reachable by making a real commit in a test. {@see WatchRun} had no tests at
 * all for exactly that reason, and the defect lived in the seam.
 *
 * EVERY METHOD RETURNS NULL FOR "CANNOT DETERMINE", never a reassuring default.
 * A tarball install with no .git is a legitimate deployment and must not fail;
 * it must also not be told its baseline is fresh, because nothing knows that.
 */
interface GitCli
{
    public function available(): bool;

    /**
     * ISO-8601 committer date of the newest commit touching $path, or null if
     * that cannot be established -- no git, not a checkout, or no commit has
     * ever touched the path.
     */
    public function lastCommitIso(string $path): ?string;

    /**
     * How many files under $path have uncommitted changes, or null if that
     * cannot be established.
     */
    public function dirtyFileCount(string $path): ?int;
}
