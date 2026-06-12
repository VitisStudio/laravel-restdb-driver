# Progress Log

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
