# Progress Log

## G9 — Mock JSON:API server + what a real strict server forced ✅

**Shipped** (`tools/mock-jsonapi` + jsonapi adapter additions)
- Hatchify (`@hatchifyjs/koa`) mock JSON:API server over in-memory SQLite,
  seeded authors → posts → comments on boot. (bitovi/mock-jsonapi, the
  originally requested tool, turned out to be an empty README-only repo —
  hatchify is bitovi's living equivalent.)
- Driving a real strict server surfaced three adapter gaps, all closed as
  config, with tests:
  - **`dollar-operator` filter dialect** — sequelize-style
    `filter[rating][$gte]=4`, `$in`/`$nin` comma lists, `$like` with raw SQL
    wildcards (hatchify, and anything on @bitovi/querystring-parser).
  - **`resource_types` map** — strict servers reject `type: "authors"`,
    demanding their schema name (`'authors' => 'Author'`).
  - **Total-based pagination math** — hatchify sends no `links` object;
    `links.next` was the only drain signal, so multi-page `get()` would have
    silently truncated to page 1. Paginators now compute the next page from
    the current request whenever a meta total is configured; links still win
    when present. `page[size]` also never travels without `page[number]` —
    strict servers 422 on a lone size.
- Example app gains the `demo:crm` command running the full tour (dollar
  filters, compound-document `with()`, math-paginated drain, one-request
  `paginate()`/`count()` off `meta.unpaginatedCount`, UUID writes, gate)
  against the mock — verified live end to end, exit 0. The `crm` connection
  is config-only; bootstrap now registers `withExceptions()` so driver
  exceptions render instead of dying silently (pre-existing skeleton gap).
- Tests: 198 passing. Pint + Larastan max clean.



## G8 — Config-driven generic adapter + wire-format presets ✅

**Shipped** (`packages/core/src/Rest`)
- The generic adapter no longer requires hand-written classes: when a
  connection omits `compiler`/`parser`/`paginator`, config-driven defaults
  take over — `RestRequestCompiler` (filter styles `suffix`/`bracket`/`plain`,
  optional `wrapper`, `in` comma/single modes, like wildcard handling,
  between→gte+lte decomposition, identity URLs, prefix or split-param sort,
  PATCH/PUT writes with optional body wrap, fields/include params),
  `JsonResponseParser` (bare bodies or `response.data`/`response.errors`
  dot-path envelopes, configurable `id_key`), and `QueryParamPaginator`
  (param names from config; the params you name are the page.* capabilities
  you get; totals from a header or body path power one-request paginate()
  and count(); no-total APIs probe forward until an empty page).
- **Presets**: `'preset' => 'json-server'` expands a connection-config
  fragment — wire format AND a capabilities block declaring what the named
  framework honors (baseline stays NONE; presets *declare*, nothing is
  derived). User presets live in `config/restdb.php` under `presets`, win
  over built-ins by name; declared connection keys always win over the
  preset (recursive merge for maps, wholesale for lists/scalars).
- Fail-loud preserved everywhere: flat styles throw on nested groups, OR
  booleans, and conditions that collide on one query parameter; every
  config-shaped refusal names the exact key to set (`filters.in`,
  `fields.param`, `pagination.params.page`, …). Unknown presets list the
  available names.
- Example app rewrote itself out of a job: `app/RestDB` (3 classes, ~280
  lines) deleted; the jsonplaceholder connection is now `preset:
  json-server` + base_url. Demo behavior unchanged.
- Tests: 193 passing — compiler/parser/paginator units, preset merge
  semantics, end-to-end preset connection (wire format, gate from preset
  capabilities, overrides, user presets shadowing built-ins, unknown-preset
  error, conformance kit on preset config alone). Pint + Larastan max clean.

**Notes**
- Custom strategy classes remain the escape hatch for APIs that outgrow
  configuration; `generic` still starts from capability NONE without a
  preset or declared block.
- Cursor pagination intentionally not in `QueryParamPaginator` (generic
  cursor APIs vary too much) — a custom paginator class covers it.



## Example app — Eloquent over live JSONPlaceholder ✅

**Shipped** (`examples/jsonplaceholder`)
- Minimal Laravel 12 console app wired to the monorepo via a path repository
  (versions pinned so inter-package `^0.1` constraints resolve).
- JSONPlaceholder is json-server, not JSON:API — so the example showcases the
  generic adapter with three small classes (~150 lines): compiler
  (`?userId=1&id_gte=5&_sort=…`), parser (bare arrays), paginator
  (`_page`/`_limit`/`_start` + the `X-Total-Count` header, which contributes
  page.total). A reference `crm` connection shows the zero-code json-api
  adapter config. README explains the distinction.
- `php artisan demo` runs five live sections — queries, pagination (ONE
  request paginate/count off the header total, streaming lazy()), relations
  (eager belongsTo, lazy hasMany, whereHas decomposition), writes (POST with
  id 101 re-fill, dirty-only PATCH, DELETE), and the gate (orWhere/select/
  multi-whereIn/groupBy all failing loudly). Every wire request prints via
  DB::listen. Verified end to end against the real API, exit 0.

**Core fixes the live API forced** (with regression tests, 157 total now)
- `whereIntegerInRaw`/`whereIntegerNotInRaw` were hard-throw, but Eloquent
  uses them as the integer-key eager-load optimization — now delegate to the
  gated `whereIn`, so relations on int-key models work.
- HasMany/HasOne constrain `fk = ?` AND `fk IS NOT NULL`; the NotNull is
  logically implied, so demanding a `not-null` operator broke lazy loads.
  IntentFactory now builds → prunes implied NotNulls → gates survivors, with
  a matching eager-gate exemption. A developer's un-implied whereNotNull
  still gates.



## G7 — Hardening ✅ (all goals complete)

**Shipped**
- **Version-drift canary**: reflection test diffing
  `Illuminate\Database\Query\Builder`'s 260 public methods (Laravel 12.x)
  against an audited allowlist — CI fails naming any new surface until it is
  gated, throw-listed, or confirmed to funnel into a gated path.
- **Octane suite**: two connections in one process with different capability
  sets — zero cross-talk, instance-state-only proven (plus the OAuth2
  token-isolation test from G2); purged connections carry no stale state.
  A source scan asserts no static properties exist in any package class.
- **Adapter conformance kit** (`Vitis\RestDB\Testing\AdapterConformanceKit`):
  executable definition of a valid adapter — EmptyResult for provably-empty
  queries, usable compiled requests, single-resource writes, error/row
  parsing, drain termination. Runs against the JSON:API adapter (both
  dialects × all three paginators) and the generic adapter. It immediately
  caught the test fixture compiler skipping the EmptyResult contract.
- READMEs: root (quick start, capability model, commands) + one per package.

**Deferred from the v1.0 wishlist** (documented, not silently skipped)
- Capability *provenance* in exception messages (which layer granted/denied)
  — needs CapabilitySet to carry source labels; clean follow-up.
- JSON:API relationship writes (attach/detach/sync) — from G5.
- Native page[cursor] streaming for cursorPaginate — from G5.
- Strict mode cross-checking echoed params/links.self (plan's own v1.0
  stretch goal for the capability-drift risk).

**Final state**: 153 tests / 510 assertions, Larastan level max clean, Pint
clean, monorepo validate clean. Contracts are exercised by two adapters and
the conformance kit; the JSON:API adapter is built through public contracts
alone (enforced by arch tests + composer boundaries).



## G6 — Codegen + discovery ✅

**Shipped**
- `restdb:make-models {connection} --spec= --path= --namespace= [--force]`
  (jsonapi package): one physical class per resource type — IsJsonApiResource
  trait, $connection, $table, $casts from schema types
  (integer/number/boolean/date-time/array), belongsTo/hasMany methods from
  spec relationships (skipped with a comment when the target resource is not
  in the spec), @property docblock. Skips existing files without --force —
  edited classes stay byte-identical. Generated files lint clean and run end
  to end against the fake API in tests.
- `restdb:discover {connection} --spec= [--check]` (core, adapter-supplied
  SpecParser): writes a committed manifest
  ({manifest_path|database/restdb}/{connection}.json) of capabilities +
  per-resource attributes/relationships/filters; --check fails CI on a stale
  manifest. `JsonApiSpecParser` reads OpenAPI documents following JSON:API
  conventions (type enums in components.schemas, filter[x]/sort/include/
  fields[]/page[*] parameters).
- Runtime manifest consumption: factory layers manifest capabilities between
  the paginator and declared config — advisory and additive only (grants
  pass, denials are stripped); declared config always wins, proven by a test
  that subtracts a manifest-granted capability.
- `restdb:capabilities {connection}` prints the effective capability matrix
  and granted filter operators.



## G5 — Advanced queries ✅

**Shipped**
- `paginate()` issues exactly ONE request: page params on the wire
  (page[number] or page[offset], capability-chosen), total read from the same
  response via the configured `pagination.meta_total` path; gated on
  page.total with a simplePaginate/cursorPaginate hint, and a cursor-only
  connection points to cursorPaginate.
- `count()` emulation, one request either way: adapters that answer count
  intents return an aggregate row (generic fixture), otherwise a limit-1
  probe reads the meta total (JSON:API).
- `whereHas(relation, callback)` decomposition: inner relation query →
  pluck/unique keys → outer whereIn; belongsTo/hasOne/hasMany; capped by
  `guards.where_has_max_keys` (throws, never truncates); zero matches
  short-circuits the outer query to zero HTTP via the provably-empty whereIn.
  orWhereHas / counted has() / nested dots / withCount throw loudly.
- `cursorPaginate()` works through the framework's cursor-condition
  compilation — nested comparison filters ride the nested-operator dialect
  with filter.nested + filter.or declared; page two carries
  `filter[id][gt]=…`. Native page[cursor] streaming deferred (no framework
  hook for server cursors in LengthAware-style pagination).
- Connection exposes `lastPageInfo()`; drain computes page info before the
  limit-break so single-page queries still observe totals.

**Deferred**
- JSON:API relationship writes (attach/detach/sync via
  /relationships/{name} endpoints) — needs a pivot-less BelongsToMany story;
  moved to the v0.6+ backlog rather than half-shipped.
- `Model::withCount([])` is called by Eloquent on every query — the override
  allows the empty call and throws for real usage.



## G4 — JSON:API adapter ✅

**Shipped** (`packages/jsonapi`, registers itself via its own provider)
- `JsonApiRequestCompiler`: filter/sort/fields[type]/include/page params,
  vnd.api+json headers; a lone primary-key equality compiles to the resource
  URL (`GET /posts/42` — identity, not filtering); writes are typed resource
  documents (POST, dirty-only PATCH with type+id, DELETE).
- `JsonApiResponseParser`: flattens data to snake_case rows via NameMapper
  (camel default, kebab/none configurable), exposes to-one linkage as
  `{relation}_id`, builds a (type,id) identity map over data + included that
  accumulates across pages, maps error-document pointers to field-keyed
  validation messages.
- **Compound-document eager loading**: unconstrained `with()` paths ride
  `include=` and hydrate from the identity map — `with('comments.author')`
  costs zero extra HTTP, recursively. Constrained closures, missing linkage,
  or disabled `select.include` fall back to standard eager-load queries;
  all-or-nothing per relation, nothing partial, nothing dropped.
- Three paginators (page-number / offset / cursor) sharing `links.next`
  followed verbatim; TotalCount contributed only when `pagination.meta_total`
  is configured. Two dialects (comma-list default, nested-operator) whose
  `supports()` feeds the connection's operator capabilities. Honest baseline:
  spec-guaranteed capabilities only.
- `IsJsonApiResource` trait: composes the core trait, string non-incrementing
  keys, the compound-document Eloquent builder.
- Tests: 127 passing — wire-format assertions, dialect operator granting,
  links.next drains, zero-extra-HTTP include hydration (nested + null +
  to-many empty), constrained/missing-linkage fallbacks, JSON:API writes, 422
  pointer mapping.

**Notes / deferrals**
- Conformance kit ships in G7; its cases are currently covered inline.
- `Relation::noConstraints` is required when instantiating relations for
  hydration — relation constructors otherwise push gated wheres (HasMany's
  whereNotNull) onto a query that never runs.
- Eloquent's `getModels()` hydrates through a fresh builder via
  `$this->model->hydrate()`; the JSON:API builder hydrates through itself so
  linkage lands on the right instance.



## G3 — Write path ✅

**Shipped**
- insert/insertGetId/update/delete flow through compiler contracts:
  POST / dirty-only PATCH / DELETE; the connection exposes
  `lastWriteResult()` and the model trait re-fills from the server's resource
  state after every write (`performInsert`/`performUpdate` hooks) — server
  mutations and assigned ids land on the model.
- Single-resource rule: writes must target exactly one resource by primary
  key; arbitrary-where mass update/delete and multi-row insert throw with an
  Atomic-Operations pointer. 404 on update/delete = 0 affected; 404 on create
  = config error.
- 422 → `ApiValidationException extends Illuminate ValidationException`
  (field-keyed, renders like local validation).
- `oauth2_refresh_token`: refresh grant on the bare factory, rotated refresh
  tokens persisted under the same lock, `invalid_grant` → dedicated
  re-consent exception with no retry.
- **Identity-where rule (deviation worth knowing):** top-level AND
  primary-key equality (`id = ?` / `id IN (one)`) bypasses filter-operator
  capabilities in both gate phases. GET/PATCH/DELETE-by-id is the definition
  of a REST resource, not a filter — without this, `find()`/`save()`/
  `delete()` would demand `filter.eq` on the JSON:API baseline, contradicting
  the plan's own flows. Key name travels from the model trait into the
  builder (`setKeyName`); non-key columns still gate.
- Tests: 110 passing, including dirty-only PATCH bodies, server-mutation
  re-fill, clean-save = zero HTTP, mass-write refusals, 422 field mapping,
  refresh-token rotation across a 401, filterless-connection identity flows.



## G2 — Pagination + auth ✅

**Shipped**
- Streaming: `RestConnection::cursor()` generator (one page in memory,
  max_pages guard, limit short-circuit, stdClass rows per the base contract);
  gated `Builder::cursor()` over it; `lazy()/chunk()/simplePaginate()` ride
  forPage through the Limit/Offset gates; `paginate()` blocked with a
  simplePaginate hint until v0.5 meta totals.
- Drain semantics proven against a multi-page fixture paginator (offset
  strategy, `provides()` contributes Limit+Offset): drains until has-more is
  false, stops at limit, throws `ResultTruncationException` at the guard —
  never truncates silently.
- OAuth2 client credentials: cache key
  `restdb:token:{connection}:{sha1(url|id|scopes)}`, TTL `expires_in − skew`
  (floor 10s), lock-guarded fetch with double-checked read, bare-factory token
  request (no recursion), configurable store. 401 → invalidate → re-auth →
  retry exactly once, second 401 surfaces; connection-error retries share the
  same retry pipeline.
- Tests: 96 passing — multi-page drain, stream laziness (asserts request count
  mid-iteration), token reuse across rebuilt connections, expiry-skew via time
  travel, stale→fresh token on the retried request, two-connection isolation.
- Full Operator enum was already wired in G1; the matrix covers all 12.

**Notes**
- True parallel stampede can't run in one PHP test process; covered by the
  lock + double-checked-read implementation and a cache-sharing test.
  Revisit with a real parallel harness in G7 (Octane suite).



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
