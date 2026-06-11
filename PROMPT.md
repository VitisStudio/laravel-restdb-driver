# Build Prompt — RestDB Driver Monorepo

You are an autonomous engineering agent building **RestDB**: an Eloquent database
driver for RESTful APIs, shipped as a stack of composer packages from a single
monorepo.

## Source of truth

1. `plan.html` — the full implementation plan. Read the relevant section before
   writing any code for a goal.
2. `architecture.html` — interactive component map; its flows show how a query
   travels and where config and model overrides plug in.
3. This prompt. Where this prompt and plan.html conflict, this prompt wins.

## North star

> Anything that talks to an Eloquent model should be able to talk to this driver
> without knowing it isn't a database — within the capabilities the connection
> declares. Everything outside those capabilities fails loudly with an actionable
> exception. Nothing is ever silently dropped.

Every decision serves this. When in doubt: throw with a hint. Never guess, never
drop, never degrade silently.

## Hard constraints — never violate

1. **Monorepo of stacked packages.** One git repository; packages under
   `packages/`, each requiring the one below it:
   `vitis/restdb-contracts` (SPI + value objects + capabilities, depends on
   nothing) ← `vitis/restdb` (core driver) ← `vitis/restdb-jsonapi` (JSON:API
   adapter). Future adapters keep stacking the same way. Tooling:
   `symplify/monorepo-builder` (`merge` / `validate` / `bump-interdependency`);
   the root `composer.json` `replace`s every package so tests run against local
   code; `monorepo-split-github-action` mirrors each package on tag. Do **not**
   use nwidart/laravel-modules — it modularizes applications, not publishable
   packages.
2. **No silent drops.** Unknown where-type, unsupported operator, unmappable
   builder feature, truncated result set → typed exception naming the
   connection, capability, model, and the fix. A dropped filter is a
   data-exposure bug.
3. **No static state.** Octane- and queue-worker-safe. Shared state lives in the
   cache, behind locks.
4. **Config-first, model-second.** Every feature is configurable per connection
   in `config/database.php` (adapter, auth, http, pagination, dialect,
   endpoints, capabilities, guards). Models are escape hatches: they may narrow
   capabilities or override endpoint/resource type/name mapping — never widen
   what the connection denies.
5. **Generated models are committed code.** `restdb:make-models` writes physical
   classes the user owns and edits; never overwrite an edited class without
   `--force`. Runtime never parses a spec file.
6. **Builder subclass, no SQL grammar.** Custom `Query\Builder` intercepts
   terminals; the stub Grammar throws on every `compile*`.
7. **Blocking quality gates.** After every goal: Pint clean, Larastan level max
   clean on all `packages/*/src`, full Pest suite green,
   `monorepo-builder validate` passes.

## Goals

Work the goals strictly in order. A goal is done only when every acceptance
criterion is demonstrably true — a test exists and passes, or a command runs
clean. Use conventional commits, one coherent change per commit. End each goal by
appending a short entry to `PROGRESS.md`: what shipped, what was deferred, any
deviation from plan.html and why.

### G0 — Monorepo skeleton
- Root `composer install` succeeds; `packages/contracts`, `packages/core`,
  `packages/jsonapi` exist with their own `composer.json`; root `replace`s all
  three; inter-package requires point at `^0.1`.
- `vendor/bin/monorepo-builder merge` and `validate` run clean;
  `monorepo-builder.php` keeps versions in lock-step.
- Pest + Testbench workbench boots from the root; Pint and Larastan configured
  at the root covering all packages.
- CI workflow: Laravel 11/12 × PHP 8.2–8.4 matrix plus a
  `laravel/framework@dev` canary job; `split.yml` stubbed for tag-triggered
  package mirrors.
- Arch tests enforce: contracts imports nothing internal; jsonapi imports only
  contracts namespaces and core's public API; no facade usage outside
  `Facades/`.

### G1 — Read core (plan §3–§6, v0.1 scope)
- `restdb` driver registers via `DatabaseManager::extend`; no PDO is ever
  constructed; transactions and schema builder throw `never`-returning methods.
- Capability enum/set/gate with two-phase enforcement (eager at the builder
  mutator, late sweep in `IntentFactory`); immutable intent DTOs; where-type
  whitelist (Basic, In, NotIn, Null, NotNull, Between, Nested) that throws
  `UnsupportedQueryException` naming any other type.
- `GenericAdapter` (compiler/parser from config class-strings, baseline
  capabilities **none**), convention endpoint resolver, `NullPaginator`,
  Transport with timeout/retry/error mapping, auth drivers
  `none`/`bearer`/`basic`/`api_key`, core model trait,
  `find/get/first/value/pluck`, operators Eq + In.
- Acceptance: the capability matrix test — for every `Capability` case, one test
  proving the gated method throws when absent and works when present;
  `whereIn('id', [])` issues zero HTTP requests; `->groupBy()` throws
  `BadMethodCallException` naming the method; `UnsupportedCapabilityException`
  message contains connection, capability, model, builder method, and a fix
  hint; `Http::fake()` intercepts everything,
  `Http::preventStrayRequests()` armed in the base TestCase.

### G2 — Pagination + auth (plan §4.2, §7, v0.2 scope)
- Paginator contract wired into the page-drain loop; `guards.max_pages` throws
  `ResultTruncationException`; `lazy()/cursor()/chunk()/simplePaginate()`
  stream/sequence pages; full `Operator` enum.
- OAuth2 client-credentials: cache key
  `restdb:token:{connection}:{sha1(...)}`, TTL `expires_in − expiry_skew`,
  `Cache::lock()->block()` with double-checked read, token request on the bare
  HTTP factory; 401 → invalidate → re-authenticate → retry exactly once.
- Acceptance: drain terminates when has-more is false; simulated parallel token
  fetch hits the token endpoint once; a second 401 surfaces
  `RestDBAuthenticationException`; `QueryExecuted` fires with the request line
  as sql and real timing (`DB::listen` works).

### G3 — Write path (plan §4.1, v0.3 scope)
- `insert/insertGetId/update/delete` through compiler contracts; PATCH carries
  dirty attributes only; model re-fills from the write response body; 422 maps
  to field-keyed `ApiValidationException`; mass update/delete with wheres
  throws; `oauth2_refresh_token` with atomic rotation under the same lock and a
  dedicated `invalid_grant` exception.
- Acceptance: `save()` on a clean model issues zero HTTP; the update request
  body contains only dirty attributes; created id flows back through
  `WriteResult::id`; `findOrFail()` on 404 throws `ModelNotFoundException`.

### G4 — JSON:API adapter (plan §8, v0.4 scope)
- In `packages/jsonapi`, registering itself via its own service provider:
  compiler (`filter`/`sort`/`fields`/`include`/`page`), parser (identity map
  over `data` + `included`, to-one linkage as `{relation}_id`, kebab/camel ↔
  snake name mapping, error-document mapping), three pagination strategies
  (`page-number`/`offset`/`cursor`, `links.next` as the has-more signal),
  comma-list + nested-operator dialects, `IsJsonApiResource` trait, honest
  capability baseline (Filter ships with zero operators until dialect/config
  grant them).
- Acceptance: `with('comments.author')` hydrates relations from one compound
  document with zero extra HTTP; fixture documents cover
  collection/compound/paginated/error/422; the adapter passes the conformance
  kit using only public contracts; arch boundary tests still green.

### G5 — Advanced queries (plan §6, v0.5 scope)
- `paginate()` reads totals from the configured `meta_total` path in **one**
  request; `count()/exists()` emulation; `whereHas` decomposition
  (relation query → pluck keys → `whereIn`) gated on `Operator::In`;
  `cursorPaginate()`; JSON:API relationship writes
  (`attach/detach/sync/associate`).
- Acceptance: `paginate()` issues exactly one HTTP request; `whereHas` beyond
  `guards.where_has_max_keys` throws; `paginate()` without `TotalCount` throws
  with a `simplePaginate()` hint.

### G6 — Codegen + discovery (plan §8, v0.6 scope)
- `restdb:make-models {connection} --spec=path.json --path= --namespace=
  [--force]`: one physical class per resource type — trait, `$connection`,
  `$table`, `$casts` from schema types, relation methods from spec
  relationships, `@property` PHPDoc. `restdb:discover` writes the committed
  capability manifest with a `--check` CI mode. `restdb:capabilities` prints
  the effective capability matrix per connection.
- Acceptance: models generated from the fixture spec pass Larastan and work
  against `Http::fake()` end-to-end; re-running without `--force` leaves an
  edited file byte-identical; manifest entries are advisory — a declared config
  value always wins in a test that sets both.

### G7 — Hardening (plan §12–§14, v1.0 scope)
- Octane-shaped test suite: two connections in one process, zero cross-talk,
  zero static state; reflection canary diffing
  `Illuminate\Database\Query\Builder`'s public surface against the
  gated/throw-listed allowlist; capability provenance (which layer
  granted/denied) in exception messages; conformance kit published under
  `core/src/Testing`; contracts marked stable; README at the root and in each
  package documenting install, the config-first/model-escape-hatch contract,
  and the codegen workflow.

## Working loop — every goal

1. Re-read the relevant plan.html section.
2. Write the failing tests first; capability-matrix entries and conformance-kit
   cases are the executable spec.
3. Implement the minimum that passes.
4. Run all gates (constraint 7); fix everything before moving on.
5. Update `PROGRESS.md` and commit.

## Anti-goals — do not build

SQL grammar compilation · transaction emulation · `withCount`/`sum`/`avg`/
`min`/`max` · a spatie/laravel-data dependency · runtime spec parsing ·
nwidart/laravel-modules · silent fallbacks of any kind.

## Definition of done

All G0–G7 acceptance criteria pass in CI; both adapters pass the conformance
kit; `monorepo-builder validate` is clean; each package installs standalone
(verified via the split workflow's dry-run); documentation covers config keys,
model escape hatches, and `restdb:make-models` end to end.
