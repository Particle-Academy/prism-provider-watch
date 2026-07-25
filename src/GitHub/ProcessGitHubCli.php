<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\GitHub;

/**
 * Shells out to the real `gh` CLI. Uses proc_open with an ARGUMENT ARRAY (never
 * a shell string) so titles/bodies can't inject shell — and passes the issue
 * body via a temp file, not argv, so large markdown bodies never hit arg limits.
 */
final class ProcessGitHubCli implements GitHubCli
{
    public function __construct(private readonly string $binary = 'gh') {}

    public function available(): bool
    {
        return $this->run([$this->binary, '--version'])['code'] === 0;
    }

    public function findOpenIssueByTitle(string $repo, string $title): ?int
    {
        $result = $this->run([
            $this->binary, 'issue', 'list',
            '--repo', $repo,
            '--state', 'open',
            '--search', $title.' in:title',
            '--json', 'number,title',
            '--limit', '30',
        ]);

        if ($result['code'] !== 0) {
            return null;
        }

        $issues = json_decode($result['out'], true);
        if (! is_array($issues)) {
            return null;
        }

        foreach ($issues as $issue) {
            if (is_array($issue) && ($issue['title'] ?? null) === $title) {
                return (int) $issue['number'];
            }
        }

        return null;
    }

    /** @param list<string> $labels */
    public function createIssue(string $repo, string $title, string $body, array $labels = []): string
    {
        $bodyFile = tempnam(sys_get_temp_dir(), 'prism-watch-');
        if ($bodyFile === false) {
            return '';
        }
        file_put_contents($bodyFile, $body);

        $args = [$this->binary, 'issue', 'create', '--repo', $repo, '--title', $title, '--body-file', $bodyFile];
        foreach ($labels as $label) {
            $args[] = '--label';
            $args[] = $label;
        }

        $result = $this->run($args);
        @unlink($bodyFile);

        return $result['code'] === 0 ? trim($result['out']) : '';
    }

    /**
     * @param  list<string>  $args
     * @return array{code: int, out: string, err: string}
     */
    private function run(array $args): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($args, $descriptors, $pipes);

        if (! is_resource($process)) {
            return ['code' => 127, 'out' => '', 'err' => 'failed to spawn '.($args[0] ?? '')];
        }

        $out = stream_get_contents($pipes[1]) ?: '';
        $err = stream_get_contents($pipes[2]) ?: '';
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }
}
