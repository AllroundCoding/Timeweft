<!-- project-maintained — keep above the Boost block; `php artisan boost:install` regenerates everything below -->
## Timeweft project guidelines

This is a **deterministic simulation engine** hosted in Laravel — "do it the Laravel way" applies at
the boundary, not in the pure `app/Sim` core. Two committed docs carry the project's conventions and
are the reference for **cloud Claude Code sessions that have no Laravel Boost MCP**:

- [`docs/conventions.md`](docs/conventions.md) — the two-zone rule, determinism invariants, the sweep.
- [`docs/laravel-cheatsheet.md`](docs/laravel-cheatsheet.md) — version-pinned idioms (stands in for Boost's `search-docs`).

Read both before working in this repo. The cheatsheet's version table is generated — run
`php artisan docs:check-stack` after any dependency change.

## Project state & key decisions

Knowledge transfer for any session picking this up cold. The **live plan is Linear** — this is the
why, not the backlog. Filter Linear by the `v1-core` label for the current line of work.

**Where we are.** The pure-sim core (M0–M5) is built: people, needs, relations, projects,
institutions, trade/caravans, money & cost-of-living, war, disease/contagion, festivals, cohorts. The
M6 persistence spine is built: skeleton/texture split (TWT-31), `Checkpoint` (TWT-32), `Timeline`
derive-on-demand (TWT-38), the relational schema (TWT-28), `WorldStore` hybrid save/load (TWT-30), and
the `Engine` façade (TWT-88). What remains to close **v1-core** is the view: the LLM flavor layer
(TWT-53) and the minimal renderer (TWT-54) — order is the user's call.

**Three tiers.** *v1-core* = engine + persistence + the `Engine` API + a minimal renderer/flavor view
(the base app). *v1-depth* = worldgen geography (doc 13), culture (M8), goods (M9), deep realism,
skills, per-need capacity. *v2-game* = the playable layer (doc 16). Tickets blocked by a later
milestone were moved out into a phase-2 milestone of their own topic (doc-18 concurrency, doc-16 game
phase) — a ticket lives in the milestone whose work actually unblocks it.

**The byte-identical invariant.** The canonical run `world:simulate --seed=vaeris --years=22` must stay
reproducible: same seed → same world, byte for byte. Additive, cross-settlement, or
no-op-below-two-villages features keep the hash stable; features that deliberately change behavior
(money TWT-135, sickness TWT-115) **re-baseline** the narrative on purpose. Golden tests assert on
*invariants*, not snapshots, so they survive a re-baseline. This holds because RNG is drawn only
through `App\Sim\Support\Rng` forked sub-streams keyed by (concern, entity, epoch) off the immutable
seed — the main generator accumulates no draw-state, so **seed + boundary state is enough to resume**.
That is what makes `Checkpoint` a plain `serialize()` and resume byte-identical.

**Architecture in a breath.** Canonical events + entities are the persisted *skeleton*; per-tick
activity and need values are *texture* — recomputed on demand, never stored (marker interfaces in
`app/Sim/Persistence`). `Checkpoint::of(World)` captures boundary state for exact resume; `Timeline`
reconstructs any past tick from the nearest checkpoint ≤ tick, then advances. `App\Sim\Engine` is the
only public entry point (`seed · advance · query · steer`). `App\Persistence\WorldStore` is the
boundary: relational rows for queryable projection **and** a checkpoint blob for byte-identical resume.
LOD/cohorts (TWT-49/50/51) track salient individuals vs statistical cohorts — note the **LOD manager
is not yet wired into the run loop**, which is the headline scaling gap (per-tick cost should scale
with living salient cast + cohorts, not world age).

**Database stance.** Postgres/Timescale for real use, sqlite for tests only; migrations are portable
across sqlite/mysql/postgres (JSON via a portable helper, JSON queried through Eloquent/query-builder).
Postgres-only perf (GIN, TimescaleDB) goes behind a driver gate. Perf tests run against real setups,
never sqlite — deferred until there's a real need. Agent rows are typed scalar columns for the hot,
queryable fields + JSONB for the flexible bags (traits, needs `{value,capacity}`, job history,
parent ids). Species/race is data-driven (presets + world deltas, "sapience is a trait"), so the
string `species` column + JSONB is forward-compatible (TWT-201).

**Per-ticket rhythm.** One ticket per branch off `main` — never stack branches. The gate before push:
tests + `vendor/bin/pint` + PHPStan + the byte-identical canonical hash + `php artisan docs:check-stack`.
Push `-u origin <branch>`, set the Linear ticket to *In Review*, and the human merges before the next
ticket starts. Don't open PRs unless asked. File gaps/follow-ups as Linear tickets named
`Sim | Area: …` rather than letting scope creep into the current branch.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
