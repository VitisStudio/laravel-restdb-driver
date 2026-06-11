# Progress Log

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
