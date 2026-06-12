<?php

declare(strict_types=1);

return [

    'default' => 'jsonplaceholder',

    'connections' => [

        /*
        | JSONPlaceholder is plain REST (json-server), not JSON:API — so this
        | connection uses the generic adapter with the built-in 'json-server'
        | preset. The preset carries the whole wire format (suffix filters,
        | _sort/_order, _page/_limit, X-Total-Count totals) AND the capability
        | block declaring what json-server honors; everything else fails
        | loudly at the call site. No custom classes anywhere.
        */
        'jsonplaceholder' => [
            'driver' => 'restdb',
            'adapter' => 'generic',
            'base_url' => 'https://jsonplaceholder.typicode.com',
            'auth' => ['driver' => 'none'],
            'preset' => 'json-server',
            'http' => ['timeout' => 10, 'connect_timeout' => 5],
        ],

        /*
        | A real JSON:API backend on the preconfigured vitis/restdb-jsonapi
        | adapter — zero custom classes, config only. Defaults to the
        | hatchify mock server in tools/mock-jsonapi (`npm start` there,
        | then `php artisan demo:crm` here). Hatchify speaks dollar
        | operators (filter[rating][$gte]=4), wants its schema names as the
        | type member, sends totals in meta.unpaginatedCount, and omits
        | links — all of it config.
        */
        'crm' => [
            'driver' => 'restdb',
            'adapter' => 'json-api',
            'base_url' => env('CRM_API_URL', 'http://localhost:3010/api'),
            'pagination' => ['strategy' => 'page-number', 'size' => 5, 'meta_total' => 'meta.unpaginatedCount'],
            'filter_dialect' => 'dollar-operator',
            'resource_types' => ['authors' => 'Author', 'posts' => 'Post', 'comments' => 'Comment'],
            'capabilities' => ['page.total' => true, 'aggregate.count' => true, 'aggregate.exists' => true],
        ],

    ],

];
