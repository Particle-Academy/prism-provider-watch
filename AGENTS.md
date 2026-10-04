# AGENTS.md — particle-academy/prism-provider-watch

A workstation microservice that watches provider APIs — model rosters, OpenAPI
specs, SDK releases — and reports the drift `prism` needs to keep current.

> **Read [the shared guide](https://github.com/Particle-Academy/prism-parity/blob/main/docs/AGENTS.md)
> first** — the boundary, the gates, the binding decisions, the review skills.
> This file is only what is true of *this* repository.

## Not a Laravel package

The only repository here that is not. It is a `symfony/console` application run
from `bin/prism-watch`, it does not depend on `particle-academy/prism`, and it
must not start doing so — a watcher that imports the thing it watches can no
longer tell you that the thing is out of date.

```sh
composer watch     # bin/prism-watch run
```

## PHP ^8.3, and the platform pin exists for a reason

`composer.json` sets `config.platform.php = "8.3.0"`.

That is not cosmetic and it is not a leftover. Without it the lockfile resolves
against whatever PHP the machine that ran `composer update` happened to have,
which is routinely newer than the declared floor — producing a lock that is not
installable on the version the package says it supports. The CI job that
installs on 8.3 is what makes this visible; the pin is what makes it correct.

**Do not remove it to quiet a resolver complaint.** Removing it makes the
complaint go away and the incompatibility survive.

## Drift is a diff against a committed baseline

The mechanism is: fetch the authoritative machine-readable signal each provider
emits, diff it against a committed snapshot, report what moved.

Which means **`state/` is data the package depends on, and it is committed on
purpose.** A baseline that is regenerated rather than reviewed reports "no
drift" forever — the diff is against yesterday's fetch instead of against what
was last understood. When a run reports drift, the baseline advances *after*
someone has read what changed, not as part of the run.

**That rule went nineteen days unobserved, so it is now measured.** The run prints
how old the committed baseline is on every pass and exits `22` when state files
have been uncommitted past `PRISM_WATCH_BASELINE_MAX_AGE_DAYS` (default 2). It
still does not commit anything — reconciliation stays a human review step, which
is the whole point of the rule above. What changed is that the gap between what
the differ compares against and what was last understood can no longer be held
without somebody being told. Outside a git checkout the check reports that it
cannot tell, rather than that the baseline is fine.

`reports/` is output. Sectioning and formatting there are read by humans and by
the Lab; keep the structure stable enough to parse.

## Only authoritative sources

The value of this service is that its inputs are machine-readable and published
by the vendor: model list endpoints, OpenAPI documents, SDK release feeds.

Scraping a documentation page or a changelog blog would widen coverage and
destroy the signal-to-noise ratio that makes the reports worth reading — a
watcher that cries wolf gets muted, and a muted watcher is worse than none
because it still looks like coverage.

## Gates

```sh
composer test && composer types && composer format
```

CI runs `tests`, `phpstan`, `formatting`. Network calls belong behind fixtures
in the suite; a test that reaches a live provider is a test that fails when the
provider has an outage and teaches everyone to ignore red.
