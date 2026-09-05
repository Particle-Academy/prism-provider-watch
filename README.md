# prism-provider-watch

A tiny workstation microservice that keeps **[prism](https://github.com/particle-academy/prism)**
current with the AI providers it wraps. It watches the authoritative,
machine-readable signals each provider emits, diffs them against a committed
baseline, and reports **drift** — new models, deprecations, spec/SDK changes —
so schema and feature updates get filed the day upstream ships them, not weeks
later when something breaks.

> **Working on this package?** Read **[`AGENTS.md`](AGENTS.md)** first — the boundary
> this package has to hold, the gates that must be green, and the traps that have
> already caught someone.
> `@link AGENTS.md`

## Why polling, not "subscribe"

No major provider offers a push feed for "our API changed." So the honest,
reliable design is a **scheduled watch-and-diff**, plus the one genuinely
push-adjacent signal that does exist: the public, no-auth **GitHub Atom feeds**
on each provider's spec / SDK repos. That's as close to a subscription as
reality allows — and it needs no keys.

## What it watches

| Source type | Catches | Needs a key? |
|---|---|---|
| `models-dev` — a provider's roster from the shared **[models.dev](https://models.dev)** catalog | new / removed models (across every provider, one keyless fetch) | no |
| `github-atom` — a repo's `releases`/`commits`/`tags` `.atom` feed | spec + SDK changes (new params, features, versions) | no |
| `models` — *(legacy)* the provider's own `/models` endpoint | new / removed model IDs | yes |

Model rosters come from **models.dev** — a public, keyless catalog of ~180
providers / 6,000+ models — fetched **once per run** and shared across every
provider. That replaced per-provider `/models` polling, so the watcher needs
**no API keys**. The `models` endpoint source is kept for any provider models.dev
doesn't cover.

Registered providers: **OpenAI, Anthropic, Google Gemini, Mistral, Groq, xAI,
DeepSeek, OpenRouter, Perplexity, Qwen** — every provider prism supports that
models.dev catalogs. Add more by editing
[`config/providers.php`](config/providers.php) — it's data-only.

## How drift maps back to prism

prism doesn't hardcode model lists (models are free strings), so its staleness
lives in **`src/Enums/Provider.php`**, the per-provider request mappers under
**`src/Providers/<Provider>/`**, and **`config/prism.php`**. Every drift report
annotates each affected provider with exactly those paths (`prism_hints`) to
scope the follow-up — the watcher **reports**, it never edits prism.

Model-roster drift also doubles as an early-warning signal for the sandbox
Prism Lab test matrix and the docs benchmarks.

## Install & run

```bash
composer install
cp .env.example .env      # optional — rosters (models.dev) + Atom feeds are keyless

# One full pass: poll → snapshot → diff → persist baseline → report
php bin/prism-watch run

# Restrict to some providers; open a prism issue on drift; fail non-zero on drift
php bin/prism-watch run --only openai,anthropic --issues --fail-on-drift
```

- **Baselines** are committed JSON under [`state/`](state) — one file per
  provider. Their git diff *is* the provider changelog.
- **Reports** are written to `reports/latest.{md,json}`. Point
  `PRISM_WATCH_REPORT_DIR` at the envelope's `.ai/knowledge/provider-watch/` to
  make each report durable, agent-readable shared knowledge.
- Exit code: `0` normally, `20` with `--fail-on-drift` when drift is found (so a
  scheduler can branch into a follow-up).

### `--issues` has a precondition, and it fails quietly without it

Issues are filed with the label **`provider-watch`**, and `gh issue create`
refuses outright when the label does not exist on the target repo. The
dispatcher reports `gh issue create returned no URL (check auth/labels)` and
carries on, so a scheduled run drops the finding and still exits 0.

Create it once per target repo:

```bash
gh label create provider-watch -R <owner>/<repo> --color FBCA04   --description "Filed by prism-provider-watch: upstream provider API drift"
```

This bit 2026-09-05: `--issues` was switched on for the daily run against
`particle-academy/prism`, where the label had never been created. Every finding
would have been detected correctly, formatted correctly, and thrown away — the
same shape as the rest of this tool's failure modes, one layer further out.

## Autonomy (phase 1: detect → report)

On drift the run **writes a knowledge report** (always) and, with `--issues`
(or `PRISM_WATCH_OPEN_ISSUES=1`), **opens a `provider-watch` issue** on prism —
idempotent per day, so re-runs don't spam. A human triages and drives the
prism update. Nothing is auto-merged, ever.

The **Tynn story** half of the contract is filed by the triaging agent (the CLI
is a cron process and can't reach MCP); see the Genie wiring below. Phase 2 —
once the differ has earned trust — a Genie cron can dispatch a coding agent to
draft the prism patch on a branch, gated by the `pr-security-review` skill
before any human merge.

## Genie wiring (the "microservice" part)

This is a poller, so it needs no HTTP surface — a **Genie cron** is the whole
service:

- **CronCreate** → `php bin/prism-watch run --issues` daily (feeds/specs) and,
  if you want, `--only openai,anthropic,gemini` hourly for the model rosters.
- On a `--fail-on-drift` non-zero exit, a follow-up trigger can **runAgent** an
  agent that reads `reports/latest.md`, opens the Tynn story, and (phase 2)
  drafts the prism patch.
- Point `PRISM_WATCH_REPORT_DIR` at `.ai/knowledge/provider-watch/` so every
  agent on the workstation can read the current provider state.

## Workflow integration — Genie + Tynn (hybrid)

On drift, the watcher can route the change straight into the workstation's
tools. It supports **both** ways a Genie cron might fire it:

**Shell mode (the cron runs a command).** The watcher POSTs a stateless MCP
`tools/call` to each server — endpoints (and any auth token) are read at runtime
from the envelope's `.mcp.json` and `$GENIE_MCP_URL`, never hardcoded:

```bash
php bin/prism-watch run --issues --genie --tynn
```
- `--genie` → records a **Genie knowledge** node (current provider state + what
  changed), readable by every agent on the workstation.
- `--tynn` → files a **Tynn backlog story** per drifted provider, titled
  "Provider drift: <provider> …", with the change list and the prism source
  files to review. Stories attach to `PRISM_WATCH_TYNN_VERSION`, else the active
  version (resolved live via Tynn `next`).

**Agent mode (the cron fires an agent turn).** Run the watcher plain — it always
writes the structured `reports/latest.json` — then have the agent read that file
and call the `mcp__genie__knowledge` / `mcp__tynn__create` tools directly. Same
outcome, richer judgement (the agent can dedupe, map to exact prism symbols, or
draft the patch). No flags needed; the JSON report is the contract.

The heavy lifting stays third-party: point the OpenAI diff at **oasdiff**
(`openai-openapi`) for exact breaking-change detail; this layer owns only the
last mile into your workflow.

## Test

```bash
composer test     # Pest — differ, drift report, dispatcher, snapshot store
composer types    # PHPStan
composer format   # Pint
```

The differ, report renderer, and dispatchers are pure logic — the whole suite
runs with no network and no live keys.
