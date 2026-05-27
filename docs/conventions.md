# Engineering conventions

> The judgement-call companion to the version-specific [`laravel-cheatsheet.md`](laravel-cheatsheet.md)
> and the auto-generated Laravel Boost guidelines in [`/CLAUDE.md`](../CLAUDE.md). Written so a
> contributor — or a **cloud Claude Code session that has no Laravel Boost MCP** — can hold the same
> bar without the tooling installed.

Timeweft is a **deterministic simulation engine** that happens to be hosted in Laravel. That single
fact drives everything below: "do it the Laravel way" is correct at the edges and actively *wrong* in
the core. The engine is fully deterministic - same seed + same inputs → same world - and Laravel's
conveniences (`now()`, the container, facades, global helpers) are exactly the hidden, ambient inputs
that break it.

**Determinism is the *mechanism*; be precise about what must actually reproduce.** Three tiers (see
[design doc 09](design/09-causality-editing-ripple.md), and TWT-314 / TWT-313):

1. **Worldgen - the map (layout & feel): byte-identical, always.** The one hard reproducibility
   contract. Same seed → same map, every run, every mode. This is the regression gate's exact anchor.
2. **Canon - lore-bound waypoints: *reached*, path-free.** Authored milestones / end-state waypoints
   (the story director) must be *satisfied* - destination C is met whether the world got there via
   path A or path B. Assert the outcome, not the path; if a decision makes a waypoint unreachable,
   escalate to GM conflict resolution rather than fail silently.
3. **Emergent history: free to diverge - by design.** Everything else is path-dependent and *meant* to
   vary; one different decision redefines whole empires. Behavior-changing features re-baseline history
   on purpose. The gate checks **behavioral invariants** (bounded values, no negative resources,
   population within carrying-capacity, no degenerate equilibria), never a byte-for-byte history.

The RNG discipline below is what makes all three possible - a reproducible map, a replayable/resumable
run, an optional exact-regression mode - but it does *not* mean every byte of history is frozen.
**Protect the map and the canon; let the history live.**

## The two-zone rule

| Zone | Lives in | Policy |
|------|----------|--------|
| **Boundary** | `app/Http`, `app/Console`, `app/Models`, `app/Providers`, `config/`, `routes/`, `database/` | Use Laravel idioms. Eloquent + migrations, Form Requests, the service container, `config()`, API Resources, Carbon, first-party packages. |
| **Deterministic core** | `app/Sim` | Stay framework-agnostic and pure. Plain PHP, explicit inputs, no framework reach-ins. The boundary calls *into* here; this never calls *out*. |

The boundary may depend on the core. The core must never depend on the boundary. If a class under
`app/Sim` needs `use Illuminate\…`, that is a smell — push the framework concern out to the boundary
and pass the result in.

## Core invariants (`app/Sim`)

### 1. Determinism — same seed → same chronicle

- **Randomness comes only from [`App\Sim\Support\Rng`](../app/Sim/Support/Rng.php).** It wraps a
  seeded `Random\Randomizer` (Mt19937). Never call `rand()`, `mt_rand()`, `random_int()`,
  `array_rand()`, `shuffle()`, `Str::random()`, `Str::uuid()` in the core.
- **Use `fork()` / `stream()` for new draws.** A new RNG consumer must take its own salted sub-stream
  so it can't shift the numbers an existing consumer already draws (e.g. adding harvest variance must
  not perturb the seeded births and deaths). Adding a draw mid-stream is a determinism bug even when
  the seed is unchanged.
- **There is no wall-clock to inject.** In-world time is *an input, not an ambient read*: a tick
  number projected through [`TharadiCalendar::fromTick()`](../app/Sim/Time/TharadiCalendar.php) into an
  immutable [`TharadiDate`](../app/Sim/Time/TharadiDate.php). Never reach for `now()`, `Carbon::now()`,
  `time()`, or `date()` in the core — the tick already carries the time.

### 2. No hidden inputs

- **Inject dependencies through the constructor.** Every input to a tick should be visible in a
  signature and reproducible in a test. No facades, no `app()`, no `config()`, no global helpers inside
  `app/Sim` — those read ambient state the chronicle can't see.
- Engines receive their `Rng` (and any collaborators) explicitly; they do not resolve them.

### 3. Typed over stringly-typed

- Backed **enums** (e.g. [`TraitType`](../app/Sim/Traits/TraitType.php)), `readonly` value objects
  (e.g. `TharadiDate`), and the [trait registry](../app/Sim/Traits/TraitRegistry.php) over array bags
  and string keys. The type system is the cheapest test we have.

### 4. Deterministic iteration

- Whenever you iterate a collection or map that feeds the chronicle, the order must be stable across
  runs. Sort by an explicit key; never rely on insertion order of an associative array you mutated, or
  on hash order.

## Skeleton vs texture

The engine keeps two layers ([design doc 01](design/01-architecture.md)), and the distinction is
explicit in code so it's unambiguous what must be stored vs what can be regenerated:

- **Skeleton** — the sparse, canonical, *persisted* timeline: the
  [`Chronicle`](../app/Sim/Chronicle/Chronicle.php) of events plus the path-dependent entities and
  ledgers you can't recover from the seed alone (you only know who exists in year 800 by having run the
  world there). Canonical types implement the [`Skeleton`](../app/Sim/Persistence/Skeleton.php) marker;
  [`World::skeleton()`](../app/Sim/World/World.php) returns the whole world's persistable skeleton (seed,
  tick, chronicle, settlements, ledgers) — the seam persistence ([TWT-28](https://linear.app/allroundcoding/issue/TWT-28)/30)
  and checkpoints (TWT-32) work against.
- **Texture** — the dense, *derived* detail: "what is X doing at noon on day 3", a pure function of
  (skeleton/checkpoint, seed, query), recomputable on demand and never the source of truth. Derived
  types implement the [`Texture`](../app/Sim/Persistence/Texture.php) marker (e.g.
  [`Activity`](../app/Sim/Behavior/Activity.php)); they are not persisted — storage grows with
  *attention*, not time × population.

A mixed entity carries both: an agent's identity, traits, and life events are skeleton; its current
activity and the exact value of its needs at an arbitrary tick are texture (re-derived by replaying from
the nearest checkpoint). When adding state, decide which side it's on — persist the skeleton, mark and
recompute the texture.

## The sweep

The core's purity is checkable, not aspirational. As of this writing the sweep is **clean — zero
violations**. Re-run it before finalizing core changes:

```bash
# Should print nothing. Any hit is a determinism/purity smell to justify or fix.
grep -rnE "now\(\)|Carbon|mt_rand|\brand\(|array_rand|shuffle\(|Str::random|Str::uuid|\benv\(|\bconfig\(|app\(\)|use Illuminate" app/Sim
```

Static analysis (Larastan custom rules, [TWT-186](https://linear.app/allroundcoding/issue/TWT-186))
will eventually enforce these bans automatically; until then the grep above and review are the gate.

## Boundary conventions

At the edges, follow the [Boost guidelines](../CLAUDE.md) and standard Laravel practice:

- **Config, never `env()` outside `config/`.** Read configuration with `config('services.linear.key')`,
  as the console commands already do.
- **Eloquent + migrations** over raw SQL ([TWT-28](https://linear.app/allroundcoding/issue/TWT-28));
  JSONB trait bags keep the schema flexible as traits grow.
- **Database portability — Postgres/Timescale for real, sqlite for tests.** The schema runs on
  **PostgreSQL** (the target for real worlds — JSONB + GIN, and the dormant TimescaleDB option) and on
  **sqlite** (tests only — `jsonb()` maps to `text`); MySQL works too. Keep it portable: migrations use
  only Laravel's schema builder (no raw driver DDL), and queries go through Eloquent / the query-builder
  JSON helpers (`whereJsonContains`, `col->key`), never hand-written Postgres SQL. Postgres-only
  *optimizations* (GIN indexes, TimescaleDB hypertables) belong in **driver-gated** migrations as opt-in
  performance, not correctness. Never run sqlite for real use, and run **performance tests against the
  real database**, not sqlite.
- **Artisan commands use attribute signatures** — `#[Signature]` / `#[Description]`, `handle(): int`,
  `self::SUCCESS` / `self::FAILURE`. See [`PullDesignDocs`](../app/Console/Commands/PullDesignDocs.php)
  for the house pattern.
- **Named routes** and the `route()` helper for links; **API Resources** for any API surface.

## Testing

- **PHPUnit** (not Pest — the foundational context names Pest, but the suite is PHPUnit; follow what's
  installed). Most tests are feature tests; the determinism guarantee is the headline invariant —
  same seed must reproduce the same chronicle.
- Run the minimum: `php artisan test --compact --filter=SomeTest`. Use model factories and their
  custom states for any boundary/persistence tests.

## Style & static analysis

- **Pint** is the style authority. Run `vendor/bin/pint --dirty` before finalizing any PHP change;
  CI runs `pint --test` ([TWT-186](https://linear.app/allroundcoding/issue/TWT-186)).
- **Larastan/PHPStan** (target level ratcheting toward 8/9, with a baseline for existing debt) is the
  static-analysis gate landing under the same ticket. Tooling catches the mechanical; this document
  catches the judgement calls.

## Local performance — CLI JIT

The headless sim is CPU-bound PHP, and **enabling opcache + JIT for the CLI roughly halves sim runtime**
(a 200-year single-village run: ~33s → ~17s) — and it is **byte-identical**, the canonical hash is
unchanged with JIT on. Worldgen *generation* is allocation-bound and does not benefit (its noise
hot-path is optimised in code instead — [TWT-263](https://linear.app/allroundcoding/issue/TWT-263)).

In the CLI `php.ini`, opcache + JIT should be on:

```ini
opcache.enable=1
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=64M
```

**Windows gotcha:** a large `jit_buffer_size` (e.g. Herd's default `256M`) can fail to allocate the
shared segment and then silently disables opcache *entirely* — `opcache_get_status()['opcache_enabled']`
reads `false` even though `jit.on` reports `true`, so you run fully interpreted with no warning. Use
`64M`–`100M` on Windows. Verify it actually took:

```bash
php -r "var_dump(opcache_get_status(false)['opcache_enabled']);"  # must be true
```

JIT must never change results — re-run the byte-identical canonical hash after touching these settings.

## Keeping this current

The version table in [`laravel-cheatsheet.md`](laravel-cheatsheet.md) is generated from
`composer.lock`. After any dependency change, run:

## Linear conventions (for agents)

The backlog lives in Linear (project **Timeweft**); cloud sessions have no Boost MCP, so
these are the rules a session follows when reading or writing tickets.

### Label every ticket on create
Each new issue gets three labels so it stays filterable:
- **Area** — one of the title-prefix words: `Architecture` `Time` `Agents` `Behavior`
  `Population` `Economy` `Cooperation` `Direction` `Causality` `Persistence` `Culture`
  `Worldgen` `World` `Society` `Politics` `Magic`, plus cross-cutting `Tooling` /
  `Presentation`. The label must match the `Sim | <Area>:` prefix in the title.
- **Tier** — `v1-core` (the base app: engine, persistence, Engine API, minimal
  renderer/flavor), `v1-depth` (worldgen geography, culture/M8, goods/M9, deep realism,
  skills, concurrency), or `v2-game` (doc 16 play + doc 21 management).
- **Type** — `Bug`, `Feature`, or `Improvement`. (`Work out` = needs design before build.)

### Querying efficiently
- **Narrow before listing**: combine `label` + `milestone` + `state` + `parentId`. An
  unfiltered `list_issues` of the whole project is ~190 KB and overflows context.
- Filter `state` to drop `Done`/`Canceled`/`Duplicate` for a live-work view; walk an epic
  with `parentId` instead of listing everything.

### The mirror gotcha (TWT-252)
The GitHub issue sync round-trips Linear tickets back as **project-less, team-only twins**
(they're invisible to `project=Timeweft` queries — they show as gaps in the id sequence).
The in-project original is canonical; mark the mirror `duplicateOf` it. The sync also
**reverts parent/state edits** to match GitHub (labels are unaffected) — pause it before
relying on structural Linear edits.

```bash
php artisan docs:check-stack          # regenerate the version table
php artisan docs:check-stack --check  # CI mode: fail if the committed table is stale
```
