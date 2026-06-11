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
