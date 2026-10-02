<?php

declare(strict_types=1);

namespace Prism\ProviderWatch\Console;

use Prism\ProviderWatch\Diff\Differ;
use Prism\ProviderWatch\Dispatch\AgentInboxDispatcher;
use Prism\ProviderWatch\Dispatch\FileDriftDispatcher;
use Prism\ProviderWatch\Dispatch\GenieKnowledgeDispatcher;
use Prism\ProviderWatch\Dispatch\GitHubIssueDispatcher;
use Prism\ProviderWatch\Dispatch\TynnStoryDispatcher;
use Prism\ProviderWatch\GitHub\ProcessGitHubCli;
use Prism\ProviderWatch\Http\GuzzleHttp;
use Prism\ProviderWatch\Mcp\McpClient;
use Prism\ProviderWatch\Mcp\McpEndpoints;
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
            ->addOption('genie', null, InputOption::VALUE_NONE, 'Record drift as a Genie knowledge node (via the local Genie MCP).')
            ->addOption('tynn', null, InputOption::VALUE_NONE, 'File a Tynn backlog story per drifted provider (via the Tynn MCP).')
            ->addOption('inbox', null, InputOption::VALUE_NONE, "DM the drift to an agent's AgentInbox.")
            // An OPTION as well as an env var, because the scheduled task that
            // actually runs this is a single command string on Windows, where
            // `VAR=x php ...` is not a thing. A fix that the daily run cannot
            // carry is a fix that never fires.
            ->addOption('inbox-agent', null, InputOption::VALUE_REQUIRED, 'AgentInbox agent id to DM.', getenv('PRISM_WATCH_INBOX_AGENT') ?: '')
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

        // Dispatch: GitHub issue + Genie + Tynn + inbox opt-in, THEN the file
        // report last so it can record what the others did.
        //
        // The file dispatcher used to run FIRST, which is why the archive could
        // say what changed and never what was done about it — every other
        // dispatcher reported after the JSON had already been written. When
        // gpt-6-astra was detected on 2026-09-04 and no issue exists for that
        // day, nothing on disk could say whether the issue failed, was skipped
        // as a duplicate, or was never attempted.
        $dispatchers = [];

        $issuesOn = (bool) $input->getOption('issues') || getenv('PRISM_WATCH_OPEN_ISSUES') === '1';
        $dispatchers[] = new GitHubIssueDispatcher(
            new ProcessGitHubCli,
            (string) $input->getOption('repo'),
            $issuesOn,
        );

        // Workflow integration (hybrid last mile): Genie knowledge + Tynn stories
        // over the stateless MCP tools/call. Endpoints come from the envelope's
        // .mcp.json (+ $GENIE_MCP_URL); any secret is read at runtime, never stored.
        $mcp = new McpClient($http, McpEndpoints::load(
            getenv('PRISM_WATCH_MCP_CONFIG') ?: $this->home.'/../../.mcp.json',
            getenv('GENIE_MCP_URL') ?: null,
        ));
        $dispatchers[] = new GenieKnowledgeDispatcher(
            $mcp,
            (bool) $input->getOption('genie') || getenv('PRISM_WATCH_GENIE') === '1',
        );
        $dispatchers[] = new TynnStoryDispatcher(
            $mcp,
            (bool) $input->getOption('tynn') || getenv('PRISM_WATCH_TYNN') === '1',
            getenv('PRISM_WATCH_TYNN_VERSION') ?: null,
        );

        $dispatchers[] = new AgentInboxDispatcher(
            $mcp,
            (string) $input->getOption('inbox-agent'),
            (bool) $input->getOption('inbox') || getenv('PRISM_WATCH_INBOX') === '1',
        );

        $outcomes = [];
        foreach ($dispatchers as $dispatcher) {
            foreach ($dispatcher->dispatch($report) as $line) {
                $io->writeln("<info>→ {$line}</info>");
                $outcomes[] = $line;
            }
        }

        // Written LAST, carrying every line above. The archive is now a record
        // of what was detected AND who was told, so "did anyone get told?" is
        // answerable from disk instead of inferred from the absence of an issue.
        foreach ((new FileDriftDispatcher($reportDir))->dispatch($report->withDispatch($outcomes)) as $line) {
            $io->writeln("<info>→ {$line}</info>");
        }

        // Checked BEFORE the no-drift branch, because that branch used to
        // announce "Baseline is current." on the strength of an empty change
        // list -- which is also what a run produces when every poll failed.
        // A watch that compared nothing has not verified anything, so it must
        // not exit zero whatever --fail-on-drift says: a scheduler reading the
        // status is the only thing that will ever notice.
        if (! $report->compared()) {
            $io->error(
                'Nothing was compared: '.$report->uncomparedSources.' source(s) polled, 0 against a baseline. '
                .'This is NOT a no-drift result. See the notes above for the reason.'
            );

            return 21;
        }

        if (! $report->hasDrift()) {
            $io->success(
                'No provider drift. '.$report->comparedSources.' source(s) compared against the committed baseline.'
            );

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
