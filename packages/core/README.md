# vitis/restdb

The core of the RestDB Eloquent driver: register the `restdb` database driver
and talk to **any** RESTful API through Eloquent, within the capabilities the
connection declares.

```php
'legacy_api' => [
    'driver'   => 'restdb',
    'adapter'  => 'generic',
    'base_url' => env('LEGACY_URL'),
    'auth'     => ['driver' => 'bearer', 'token' => env('LEGACY_TOKEN')],
    'compiler'  => App\RestDB\LegacyCompiler::class,   // implements RequestCompiler
    'parser'    => App\RestDB\LegacyParser::class,     // implements ResponseParser
    'paginator' => App\RestDB\LegacyPaginator::class,  // optional; default: one page, ever
    'capabilities' => [                                // baseline is NONE — declare or it throws
        'select' => true,
        'filter' => ['operators' => ['eq', 'in']],
        'sort'   => true,
    ],
],
```

```php
class Invoice extends Model
{
    use \Vitis\RestDB\Eloquent\InteractsWithRestApi;

    protected $connection = 'legacy_api';
    protected $table = 'invoices';
}
```

What you get:

- A **gated query builder**: declared capabilities work, everything else
  throws at your line with the fix in the message. SQL-only surface
  (joins, raw, locks, transactions, schema) always throws. Two-phase
  enforcement catches wheres injected by scopes and packages — nothing is
  ever silently dropped.
- Primary-key equality is **identity targeting** (`find`, `save`, `delete`)
  and never requires filter capabilities.
- Reads: page-drain loop with a `max_pages` guard that throws instead of
  truncating; `cursor()`/`lazy()` stream one page at a time; one-request
  `paginate()` over meta totals; `whereHas` decomposition with a key cap.
- Writes: POST / dirty-only PATCH / DELETE against a single resource by key;
  models re-fill from the server's response; 422 maps to Laravel's
  `ValidationException`.
- Auth: `none`, `basic`, `bearer`, `api_key`, `oauth2_client_credentials`,
  `oauth2_refresh_token` (cached, lock-guarded, 401-refresh-retry-once), or
  any class-string `Authenticator`. No static state — Octane-safe.
- `Http::fake()` works everywhere; `DB::listen`/Telescope see real request
  lines with real timing.

For JSON:API backends, install
[`vitis/restdb-jsonapi`](https://github.com/vitis/restdb-jsonapi) instead of
writing a compiler/parser by hand.
