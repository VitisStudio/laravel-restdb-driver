# Progress Log

## G1 — Read core ✅

**Shipped**
- `contracts` package: Capability/Operator enums, immutable CapabilitySet
  (additive + subtractive `applyConfig`), CapabilityGate with actionable
  exception messages (connection, capability, model, method, fix hint), all
  value objects (intents, conditions, filter groups, pages, compiled request,
  api response, write result, error bag), all SPI interfaces.
- `core` package: `restdb` driver registered via `DatabaseManager::extend`
  (no PDO ever constructed); RestConnection with page-drain loop, `max_pages`
  guard, 404→empty / 401→auth-exception / non-2xx→ApiResponseException mapping
  (Authorization redacted), QueryExecuted with the request line + real timing;
  gated Query\Builder (eager phase-1 mutator gates, hard-throw SQL surface,
  `toRequest()` instead of `toSql()`); IntentFactory phase-2 where-type
  whitelist (Basic/In/NotIn/Null/NotNull/between/Nested), qualified-column
  stripping with eq→In rewrite, raw-expression rejection, pure-AND nested
  flattening; stub Grammar (every compile* throws, L11/L12 ctor compat);
  Transport on the injected HTTP factory (Http::fake works,
  preventStrayRequests armed in tests); auth: none/bearer/basic/api_key +
  class-string passthrough, per-connection instance cache; GenericAdapter
  (baseline NONE) + AdapterRegistry + RestDB facade; ConventionEndpointResolver;
  NullPaginator; model trait + RestDB Eloquent builder with model context in
  exceptions.
- Tests: 79 passing — capability matrix (20 capabilities/operators × absent
  throws + present proceeds), read-path behaviors (zero-HTTP empty whereIn,
  count/exists emulation hooks, client-side pluck without select.columns,
  hydration, DB::listen). Pint, Larastan max, monorepo validate all clean.

**Deviations from plan.html**
- 404 maps to zero rows (find() → null; findOrFail() → ModelNotFoundException
  via Eloquent's own null check) instead of throwing RecordsNotFoundException
  from the transport — plain find() must not explode on a missing resource.
- filter.nested / filter.or are enforced late (IntentFactory) rather than
  eagerly: closures can't be inspected before evaluation, and key-value array
  wheres arrive as nested groups that flatten to plain AND conditions.
- Writes are capability-gated now but execute in v0.3 (clear LogicException).



## G0 — Monorepo skeleton ✅

**Shipped**
- Git repo initialized (`main`); monorepo layout with `packages/contracts`,
  `packages/core`, `packages/jsonapi`, each with its own `composer.json`;
  inter-package requires (`core` → `contracts ^0.1`, `jsonapi` → `core ^0.1`).
- Root `composer.json` `replace`s all three packages; psr-4 maps package
  sources so tests always run against local code.
- `symplify/monorepo-builder` 11.2 installed; `monorepo-builder.php` configured;
  `monorepo-builder validate` passes.
- Minimal service providers (core publishes `config/restdb.php` with
  guards/http defaults; jsonapi provider registers, adapter wiring comes in G4).
- Tooling at root: Pest 3 (+arch plugin), Testbench, Pint (laravel preset +
  `declare_strict_types`), Larastan level max. All gates green.
- Tests: arch boundary suite (contracts/values/capabilities import nothing from
  core or jsonapi; jsonapi never reaches core internals; core never references
  jsonapi; no debug calls) + boot/config-merge feature tests. 8 passed.
- CI workflow: PHP 8.2–8.4 × Laravel 11/12 matrix + `@dev` canary;
  `split.yml` for tag-triggered package mirrors (needs `SPLIT_ACCESS_TOKEN`
  secret + read-only repos created when first release approaches).

**Deferred / notes**
- `workbench/` dir skipped — Testbench `TestCase` with
  `getEnvironmentSetUp()`-defined connections covers the same need with less
  machinery; revisit if interactive workbench serving becomes useful.
- Canary job uses `dev-master` illuminate constraints; may need a branch alias
  bump when Laravel 13 dev opens.
