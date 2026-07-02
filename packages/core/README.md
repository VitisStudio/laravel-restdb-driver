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

## HTTP middleware — caching, rate limiting, logging

The driver deliberately does **not** own a response cache or a rate limiter.
Instead it exposes the underlying Laravel/Guzzle HTTP client so you register
whatever middleware you need. List their class names under `http.middleware`;
each is resolved from the container and applied to every request in order:

```php
'crm' => [
    'driver'   => 'restdb',
    'adapter'  => 'json-api',
    'base_url' => env('CRM_API_URL'),
    'http' => [
        'middleware' => [
            App\RestDB\CrmRateLimit::class,   // rate limiting
            App\RestDB\CrmCache::class,       // response caching
        ],
    ],
],
```

Each class must resolve to an **invokable Guzzle handler-stack middleware**
(`fn (callable $handler): callable`); a class that isn't callable is a
configuration error surfaced when the connection is built. `Http::fake()` and
`DB::listen` still see the requests. Because entries are class-strings resolved
from the container, wrap any middleware that needs constructor arguments (a TTL,
a rate) in a small class — as both examples below do.

### Rate limiting

[`spatie/guzzle-rate-limiter-middleware`](https://github.com/spatie/guzzle-rate-limiter-middleware)
sleeps until the next request is allowed, per second or per minute:

```bash
composer require spatie/guzzle-rate-limiter-middleware
```

```php
use Spatie\GuzzleRateLimiterMiddleware\RateLimiterMiddleware;

final class CrmRateLimit
{
    public function __invoke(callable $handler): callable
    {
        // Default store is in-memory (per process). For a limit shared across
        // web requests and queue workers, pass a Store backed by your cache.
        return RateLimiterMiddleware::perSecond(10)($handler);
    }
}
```

### Response caching

[`kevinrob/guzzle-cache-middleware`](https://github.com/Kevinrob/guzzle-cache-middleware)
is RFC 7234-aware and Laravel-cache backed. Its `GreedyCacheStrategy` gives a
plain TTL cache for REST APIs that send no cache headers:

```bash
composer require kevinrob/guzzle-cache-middleware
```

```php
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\LaravelCacheStorage;
use Kevinrob\GuzzleCache\Strategy\GreedyCacheStrategy;

final class CrmCache
{
    public function __invoke(callable $handler): callable
    {
        return (new CacheMiddleware(
            new GreedyCacheStrategy(
                new LaravelCacheStorage(cache()->store()),
                300, // seconds
            ),
        ))($handler);
    }
}
```

Neither package is a dependency of the driver — install only what a connection
needs. The driver supplies the seam; the policy is yours.

For JSON:API backends, install
[`vitis/restdb-jsonapi`](https://github.com/vitis/restdb-jsonapi) instead of
writing a compiler/parser by hand.
