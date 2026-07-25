<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\GitHub;

/**
 * Minimal seam over the `gh` CLI so the issue dispatcher is unit-testable with
 * a fake and never shells out during tests.
 */
interface GitHubCli
{
    public function available(): bool;

    /** @return int|null issue number of an existing open issue with this exact title, or null */
    public function findOpenIssueByTitle(string $repo, string $title): ?int;

    /**
     * @param  list<string>  $labels
     * @return string the created issue URL (or '' if creation produced no URL)
     */
    public function createIssue(string $repo, string $title, string $body, array $labels = []): string;
}
