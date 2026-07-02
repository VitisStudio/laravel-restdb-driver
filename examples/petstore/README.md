# Example: Eloquent models generated from an OpenAPI 3 spec

A minimal **Laravel 13** app whose Eloquent models — `Pet`, `Order`, `User` —
were **generated from a real OpenAPI 3.0.4 document** (the
[Swagger Petstore](https://petstore3.swagger.io/)) by the
`vitis/restdb-openapi` package, then run live against the Petstore API through
the restdb driver.

```bash
cd examples/petstore
composer install          # links the monorepo packages via path repositories
php artisan demo          # run everything (live HTTP)
php artisan demo read     # or one section: codegen|read|gate
php artisan restdb:capabilities petstore
```

## The point: `restdb:make-openapi-models`

The classes in [app/Models](app/Models) are not hand-written — they were
generated from the committed spec at [spec/petstore.json](spec/petstore.json):

```bash
php artisan restdb:make-openapi-models petstore \
    --spec=spec/petstore.json \
    --namespace="App\\Models" \
    --exclude=/uploadImage
```

The parser reads a **plain OpenAPI 3.0.3/3.0.4 document** — no JSON:API
envelope — and emits one model per schema that is actually read or written at a
path:

| Schema | Endpoint | Generated model |
| --- | --- | --- |
| `Pet` | `/pet`, `/pet/{petId}` | [`Pet`](app/Models/Pet.php) |
| `Order` | `/store/order`, `/store/order/{orderId}` | [`Order`](app/Models/Order.php) |
| `User` | `/user`, `/user/{username}` | [`User`](app/Models/User.php) |

- Attributes, `@property` docblocks, and casts come straight from the schema
  `type`/`format` (`integer`→`integer`, `string:date-time`→`datetime`, …).
- `--exclude=/uploadImage` drops the RPC action endpoint
  `POST /pet/{petId}/uploadImage` whose `ApiResponse` body would otherwise
  become a junk model. (Same flag handles Intacct-style `/workflows/…` actions.)

### Why `Pet` has no relationships

Relations are **`$ref`-to-a-resource only**: a property becomes `belongsTo`/
`hasMany` only when its schema `$ref`s another schema that is itself exposed at
an endpoint. Petstore's `Pet.category` and `Pet.tags` `$ref` `Category`/`Tag`,
but those schemas have **no endpoint of their own** — so they stay nested array
attributes, not relations. That is the honest reading of the spec; no relation
is invented from naming.

Note the models are generated with `name_mapping => 'none'` (see
[config/database.php](config/database.php)): Petstore uses camelCase members
(`photoUrls`) and the generic adapter sends attribute names verbatim, so the
columns stay camelCase to match the wire 1:1.

## Runtime — the generic adapter, config only

Petstore is plain REST (bare JSON, singular resource paths), so the connection
uses the built-in **`generic`** adapter with no custom classes — the whole
integration is [one config block](config/database.php). The endpoint resolver
maps a model's `$table` verbatim to `/{table}` and `/{table}/{id}`, so
`Pet::find($id)` compiles to `GET /pet/{id}`.

### What actually works on Petstore

Petstore is deliberately minimal (and its demo server is flaky), so the `read`
section leans on the one REST shape it reliably serves — `GET /pet/{id}`:

| Section | Shows |
| --- | --- |
| `codegen` | how the generated `Pet` maps back to the spec (table, casts, why no relations) |
| `read` | `Pet::find($id)` → `GET /pet/{id}`, nested `category`/`tags` hydrated as arrays |
| `gate` | fail-loud: Petstore has no collection filter/sort, so `where()`/`orderBy()` throw |

Petstore has **no** `GET /pet` collection endpoint and **no** query-param
filtering (its `findByStatus`/`findByTags` are RPC endpoints, not filters), so
`Pet::all()` and `where()`/`orderBy()` are undeclared capabilities that fail
loudly rather than silently returning wrong data. The demo fetches a
currently-valid pet id from `findByStatus` with a raw HTTP call, then reads it
back through the generated Eloquent model.

Every HTTP request the driver makes is printed as it executes via `DB::listen`
— real request lines (`GET /pet/1011`) with real timing, exactly like SQL.

## Requirements

- PHP 8.3+ and Laravel 13 (the app is pinned to `laravel/framework: ^13.0`).
