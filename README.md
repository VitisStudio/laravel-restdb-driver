# RestDB

**An Eloquent database driver for RESTful APIs.** Anything that talks to an
Eloquent model can talk to this driver without knowing it isn't a database —
within the capabilities the connection declares. Everything outside those
capabilities fails loudly with an actionable exception. Nothing is ever
silently dropped.

```php
$articles = Article::where('status', 'open')
    ->where('rating', '>=', 4)
    ->orderByDesc('created_at')
    ->limit(25)
    ->get();
// GET https://api.example.com/v2/articles?filter[status]=open&filter[rating][gte]=4&sort=-createdAt&page[size]=25
```

This repository is a monorepo of three stacked packages:

| Package | Requires | What it is |
| --- | --- | --- |
| [`vitis/restdb-contracts`](packages/contracts) | nothing | The SPI: contracts, value objects, capability primitives |
| [`vitis/restdb`](packages/core) | contracts | The driver: connection, gated builder, transport, auth, generic adapter |
| [`vitis/restdb-jsonapi`](packages/jsonapi) | core | A complete JSON:API v1.1 adapter, including spec-driven model generation |

## Quick start (JSON:API backend)

```bash
composer require vitis/restdb-jsonapi
```

```php
// config/database.php
'connections' => [
    'crm' => [
        'driver'   => 'restdb',
        'adapter'  => 'json-api',
        'base_url' => env('CRM_API_URL'),
        'auth' => [
            'driver'        => 'oauth2_client_credentials',
            'token_url'     => env('CRM_TOKEN_URL'),
            'client_id'     => env('CRM_CLIENT_ID'),
            'client_secret' => env('CRM_CLIENT_SECRET'),
        ],
        'pagination' => ['strategy' => 'page-number', 'size' => 50, 'meta_total' => 'meta.page.total'],
        'filter_dialect' => 'nested-operator',
        'capabilities' => [
            'page.total' => true,
            'aggregate.count' => true,
        ],
    ],
],
```

Generate physical model classes from the API's OpenAPI spec — committed code
you own and edit:

```bash
php artisan restdb:make-models crm --spec=storage/api-specs/crm.json \
    --path=app/Models/Crm --namespace="App\Models\Crm"
```

Then use them like any other Eloquent model: `find`, `where`, `with`
(compound documents — zero extra HTTP), `paginate` (one request, totals from
meta), `save` (dirty-only PATCH), `delete`.

## The capability system

A connection can only do what it declares. The `generic` adapter starts from
**nothing**; the `json-api` adapter starts from what the spec guarantees.
Capabilities layer bottom-up: adapter baseline → paginator contributions →
discovered manifest (advisory) → declared config (always wins). Models may
narrow, never widen. Anything undeclared throws at your line:

```
Connection [crm] does not support [page.limit] (used in limit() on App\Models\Article).
Hint: if the API actually supports it, add 'page.limit' under
connections.crm.capabilities — or remove the limit() call.
```

Inspect any connection with `php artisan restdb:capabilities crm`.

## Commands

| Command | Purpose |
| --- | --- |
| `restdb:capabilities {connection}` | Print the effective capability matrix |
| `restdb:discover {connection} --spec= [--check]` | Spec → committed capability manifest (advisory; `--check` for CI) |
| `restdb:make-models {connection} --spec= [--path= --namespace= --force]` | Spec → physical Eloquent classes |

## Development

```bash
composer install
composer test        # pest
composer analyse     # larastan, level max
composer format      # pint
composer monorepo:validate
```

Packages are split to read-only mirrors on tag via
`monorepo-split-github-action` (requires the `SPLIT_ACCESS_TOKEN` secret).
See [PROGRESS.md](PROGRESS.md) for the build log and
[plan.html](plan.html) / [architecture.html](architecture.html) for the
design.
