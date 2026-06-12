# Example: Eloquent over JSONPlaceholder

A minimal Laravel app that queries the live
[JSONPlaceholder](https://jsonplaceholder.typicode.com/) API through the
restdb driver — `Post`, `User`, and `Comment` are plain Eloquent models whose
"database" is HTTPS.

```bash
cd examples/jsonplaceholder
composer install          # links the monorepo packages via path repositories
php artisan demo          # run everything (live HTTP)
php artisan demo gate     # or one section: queries|pagination|relations|writes|gate
php artisan restdb:capabilities jsonplaceholder
```

There are two connections — two adapters, two demos:

| Connection | Adapter | Backend | Demo |
| --- | --- | --- | --- |
| `jsonplaceholder` | `generic` + `json-server` preset | live JSONPlaceholder | `php artisan demo` |
| `crm` | `json-api` | local hatchify mock ([tools/mock-jsonapi](../../tools/mock-jsonapi)) | `php artisan demo:crm` |

```bash
# in one terminal
cd tools/mock-jsonapi && npm install && npm start

# in another
cd examples/jsonplaceholder && php artisan demo:crm
```

## Why the *generic* adapter and not the json-api one?

JSONPlaceholder is json-server, **not** a JSON:API server: bare JSON arrays,
no `data`/`attributes` envelope, `_page`/`_limit`/`_sort` parameters. The
`json-api` adapter would be lying to it. So this example demonstrates the
driver's Layer 1 — "talk to any REST API" — and the whole integration is
**one config line**:

```php
'preset' => 'json-server',
```

The built-in preset carries the wire format (intents →
`?userId=1&id_gte=5&_sort=title&_order=desc`, bare-JSON parsing,
`_page`/`_limit`/`_start` + `X-Total-Count` pagination, which powers
one-request `paginate()` and `count()`) and the capability block declaring
what json-server actually honors. APIs with no preset describe their wire
format with the same granular keys (`filters`, `sort`, `pagination`,
`response`) in the connection or a custom preset in `config/restdb.php` —
hand-written compiler/parser/paginator classes are only for APIs that outgrow
configuration.

A real JSON:API backend uses the dedicated adapter instead: see the `crm`
connection in [config/database.php](config/database.php) — the preconfigured
`vitis/restdb-jsonapi` adapter (plus `restdb:make-models` to generate the
model classes from its OpenAPI spec).

## What the demo shows

| Section | Use cases |
| --- | --- |
| `queries` | `where` operators (`>=`, `like`), multi-`orderBy`, `limit`, `find` → resource URL |
| `pagination` | one-request `paginate()` with header totals, `count()`, streaming `lazy()` |
| `relations` | `belongsTo`/`hasMany` eager + lazy loading, `whereHas` decomposition |
| `writes` | `save()` POST with server-assigned id re-fill, dirty-only PATCH, `delete()` |
| `gate` | what fail-loud looks like: `orWhere`, `select()`, multi-`whereIn`, `groupBy` |

Every HTTP request the driver makes is printed as it executes — `DB::listen`
sees real request lines (`GET /posts?userId=1&_limit=3…`) with real timing,
exactly like SQL.

Note: JSONPlaceholder fakes writes (returns the echo, persists nothing), so
the write demo is safe to run repeatedly.
