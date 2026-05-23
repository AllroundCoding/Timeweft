# Engineering conventions

> The judgement-call companion to the version-specific [`laravel-cheatsheet.md`](laravel-cheatsheet.md)
> and the auto-generated Laravel Boost guidelines in [`/CLAUDE.md`](../CLAUDE.md). Written so a
> contributor — or a **cloud Claude Code session that has no Laravel Boost MCP** — can hold the same
> bar without the tooling installed.

Timeweft is a **deterministic simulation engine** that happens to be hosted in Laravel. That single
fact drives everything below: "do it the Laravel way" is correct at the edges and actively *wrong* in
the core. Same seed → same chronicle is the hard contract (see [design doc 09](design/09-causality-editing-ripple.md)),
and Laravel's conveniences — `now()`, the container, facades, global helpers — are exactly the hidden,
ambient inputs that break it.

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
- **Eloquent + migrations** over raw SQL once persistence lands ([TWT-28](https://linear.app/allroundcoding/issue/TWT-28));
  JSONB trait bags keep the schema flexible as traits grow.
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

## Keeping this current

The version table in [`laravel-cheatsheet.md`](laravel-cheatsheet.md) is generated from
`composer.lock`. After any dependency change, run:

```bash
php artisan docs:check-stack          # regenerate the version table
php artisan docs:check-stack --check  # CI mode: fail if the committed table is stale
```
