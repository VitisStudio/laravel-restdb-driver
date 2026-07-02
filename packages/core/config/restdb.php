<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Adapter registry
    |--------------------------------------------------------------------------
    |
    | name => class implementing Vitis\RestDB\Contracts\Adapter. Third-party
    | packages add entries via RestDB::registerAdapter(); 'json-api' is
    | registered by vitis/restdb-jsonapi's own service provider — core never
    | references jsonapi classes.
    |
    */

    'adapters' => [
        // 'generic' => Vitis\RestDB\Adapters\GenericAdapter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Wire-format presets
    |--------------------------------------------------------------------------
    |
    | name => connection-config fragment for the generic adapter. A connection
    | opts in with 'preset' => 'name'; its own keys always win over the preset.
    | Presets here win over built-ins ('json-server') of the same name. A
    | preset bundles the wire format AND a capabilities block declaring what
    | the named server framework actually honors:
    |
    | 'my-legacy-api' => [
    |     'filters'    => ['style' => 'suffix'],            // age_gte=18
    |     'sort'       => ['param' => 'sort'],              // sort=-createdAt
    |     'pagination' => [
    |         'params'     => ['page' => 'page', 'limit' => 'per_page'],
    |         'total_path' => 'meta.total',
    |     ],
    |     'response'     => ['data' => 'data'],             // {"data": [...]}
    |     'capabilities' => ['select' => true, 'sort' => true, ...],
    | ],
    |
    */

    'presets' => [],

    /*
    |--------------------------------------------------------------------------
    | Auth driver registry
    |--------------------------------------------------------------------------
    |
    | name => class implementing Vitis\RestDB\Contracts\Authenticator.
    | Built-ins are pre-registered by the AuthenticatorResolver; entries here
    | override or extend them. Connection config may also pass a class-string
    | directly as the auth driver.
    |
    */

    'auth_drivers' => [],

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    |
    | Package-level defaults, overridable per connection. max_pages bounds the
    | page-drain loop (exceeding it throws ResultTruncationException — results
    | are never silently truncated). where_has_max_keys caps the key set used
    | by whereHas() decomposition.
    |
    */

    'guards' => [
        'max_pages' => 50,
        'where_has_max_keys' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP defaults
    |--------------------------------------------------------------------------
    |
    | Per-connection overrides live under the connection's own 'http' key,
    | including 'middleware' => [Foo::class, ...] — invokable Guzzle
    | handler-stack middleware for caching, rate limiting, or logging that the
    | driver applies but does not own (see the README).
    |
    */

    'http' => [
        'timeout' => 10,
        'connect_timeout' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery manifests (v0.6)
    |--------------------------------------------------------------------------
    |
    | Where restdb:discover writes committed capability manifests. Discovered
    | entries are advisory; declared connection config always wins.
    |
    */

    'manifest_path' => null,

];
