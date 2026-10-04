<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Vcs;

use Prism\ProviderWatch\GitHub\ProcessGitHubCli;

/**
 * Shells out to the real `git`. Uses proc_open with an ARGUMENT ARRAY rather
 * than a shell string, the same way {@see ProcessGitHubCli}
 * does, so a path never reaches a shell.
 */
final class ProcessGit implements GitCli
{
    public function __construct(
        private readonly string $workingDir,
        private readonly string $binary = 'git',
    ) {}

    public function available(): bool
    {
        return $this->run([$this->binary, '--version'])['code'] === 0;
    }

    public function lastCommitIso(string $path): ?string
    {
        // -1 and %cI: the newest commit touching the path, committer date, ISO
        // 8601. `--` separates the revision range from the path so a path that
        // looks like a ref cannot be read as one.
        $result = $this->run([$this->binary, 'log', '-1', '--format=%cI', '--', $path]);

        if ($result['code'] !== 0) {
            return null;
        }

        $date = trim($result['out']);

        // Exit zero with no output means the path has no commit history, which
        // is a different thing from a failure and still not a date.
        return $date === '' ? null : $date;
    }

    public function dirtyFileCount(string $path): ?int
    {
        $result = $this->run([$this->binary, 'status', '--porcelain', '--', $path]);

        if ($result['code'] !== 0) {
            return null;
        }

        $lines = array_filter(
            preg_split('/\R/', trim($result['out'])) ?: [],
            static fn (string $line): bool => trim($line) !== '',
        );

        return count($lines);
    }

    /**
     * @param  list<string>  $args
     * @return array{code: int, out: string, err: string}
     */
    private function run(array $args): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($args, $descriptors, $pipes, $this->workingDir);

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
