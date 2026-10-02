<?php

declare(strict_types=1);

use Prism\ProviderWatch\Diff\Change;
use Prism\ProviderWatch\Diff\ChangeType;
use Prism\ProviderWatch\Diff\DriftReport;
use Prism\ProviderWatch\Dispatch\FileDriftDispatcher;

/**
 * A run re-baselines as it reports, so the NEXT run finds nothing and
 * overwrites latest.* with "no drift". Once a daily cron started firing, that
 * destroyed the only record of a 60-change drift within minutes. Drift reports
 * are archived under a timestamp so history survives the next run.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pw-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    foreach (glob($this->dir.'/archive/*') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($this->dir.'/*') ?: [] as $f) {
        is_dir($f) ? @rmdir($f) : @unlink($f);
    }
    @rmdir($this->dir);
});

function drift(string $at = '2026-08-17T07:00:00Z'): DriftReport
{
    return new DriftReport(
        [new Change('xai', 'models', ChangeType::Added, 'grok-4.6')],
        ['xai' => ['src/Providers/XAI/']],
        $at,
    );
}

it('writes latest.md and latest.json', function () {
    (new FileDriftDispatcher($this->dir))->dispatch(drift());

    expect($this->dir.'/latest.md')->toBeFile()
        ->and($this->dir.'/latest.json')->toBeFile();
});

it('archives a drift report so the next run cannot erase it', function () {
    $dispatcher = new FileDriftDispatcher($this->dir);

    $dispatcher->dispatch(drift());
    $archived = glob($this->dir.'/archive/*.md');

    expect($archived)->toHaveCount(1)
        ->and(file_get_contents($archived[0]))->toContain('grok-4.6');

    // The clean run that follows a re-baseline — it must not take the record
    // with it when it overwrites latest.md.
    $dispatcher->dispatch(new DriftReport([], [], '2026-08-17T07:25:00Z', comparedSources: 12));

    expect(file_get_contents($this->dir.'/latest.md'))->toContain('no drift')
        ->and(glob($this->dir.'/archive/*.md'))->toHaveCount(1)
        ->and(file_get_contents($archived[0]))->toContain('grok-4.6');
});

it('does not archive clean runs', function () {
    (new FileDriftDispatcher($this->dir))->dispatch(new DriftReport([], [], '2026-08-17T07:00:00Z'));

    expect(glob($this->dir.'/archive/*.md') ?: [])->toBeEmpty();
});

it('reports what it wrote', function () {
    $messages = (new FileDriftDispatcher($this->dir))->dispatch(drift());

    expect($messages)->toHaveCount(2)
        ->and($messages[1])->toContain('Archived drift');
});
