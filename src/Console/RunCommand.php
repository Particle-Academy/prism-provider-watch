<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Console;

use Prism\ProviderWatch\Diff\Differ;
use Prism\ProviderWatch\Dispatch\FileDriftDispatcher;
use Prism\ProviderWatch\Dispatch\GitHubIssueDispatcher;
use Prism\ProviderWatch\GitHub\ProcessGitHubCli;
use Prism\ProviderWatch\Http\GuzzleHttp;
use Prism\ProviderWatch\Providers\ProviderRegistry;
use Prism\ProviderWatch\Providers\SourceFactory;
use Prism\ProviderWatch\Snapshot\SnapshotStore;
use Prism\ProviderWatch\Watch\WatchRun;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `prism-watch run` — the whole pipeline in one invocation, the command Genie
 * cron calls. Poll → snapshot → diff → persist baseline → dispatch.
 */
final class RunCommand extends Command
{
    public function __construct(private readonly string $home)
    {
        parent::__construct('run');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Poll watched providers, diff against the baseline, and report drift.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated provider keys to restrict to (e.g. openai,anthropic).')
            ->addOption('issues', null, InputOption::VALUE_NONE, 'Open a GitHub issue on prism when drift is found.')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'Target repo for issues.', getenv('PRISM_WATCH_REPO') ?: 'particle-academy/prism')
            ->addOption('fail-on-drift', null, InputOption::VALUE_NONE, 'Exit non-zero when drift is found (lets cron trigger a follow-up).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $only = array_filter(array_map('trim', explode(',', (string) $input->getOption('only'))));
        $stateDir = getenv('PRISM_WATCH_STATE_DIR') ?: $this->home.DIRECTORY_SEPARATOR.'state';
        $reportDir = getenv('PRISM_WATCH_REPORT_DIR') ?: $this->home.DIRECTORY_SEPARATOR.'reports';
        $configPath = $this->home.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'providers.php';

        $http = new GuzzleHttp((float) (getenv('PRISM_WATCH_TIMEOUT') ?: 15));
        $registry = ProviderRegistry::fromConfigFile($configPath, new SourceFactory($http), array_values($only));

        $result = (new WatchRun($registry, new SnapshotStore($stateDir), new Differ))
            ->run($this->now());

        // Operational notes (skipped/failed sources) — visibility, not drift.
        foreach ($result->notes as $note) {
            $io->writeln("<comment>· {$note}</comment>");
        }

        $report = $result->report;

        // Dispatch: file/knowledge always; GitHub issue opt-in.
        $dispatchers = [new FileDriftDispatcher($reportDir)];
        $issuesOn = (bool) $input->getOption('issues') || getenv('PRISM_WATCH_OPEN_ISSUES') === '1';
        $dispatchers[] = new GitHubIssueDispatcher(
            new ProcessGitHubCli,
            (string) $input->getOption('repo'),
            $issuesOn,
        );

        foreach ($dispatchers as $dispatcher) {
            foreach ($dispatcher->dispatch($report) as $line) {
                $io->writeln("<info>→ {$line}</info>");
            }
        }

        if (! $report->hasDrift()) {
            $io->success('No provider drift. Baseline is current.');

            return Command::SUCCESS;
        }

        $io->warning(count($report->changes).' change(s) detected across '.count($report->byProvider()).' provider(s).');
        $io->writeln($report->toMarkdown());

        return $input->getOption('fail-on-drift') ? 20 : Command::SUCCESS;
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
