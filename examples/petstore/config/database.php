<?php

declare(strict_types=1);

return [

    'default' => 'petstore',

    'connections' => [

        /*
        | The Swagger Petstore is plain REST — bare JSON bodies, no envelope,
        | resources at singular paths (/pet, /pet/{id}, /store/order, /user).
        | So this connection uses the generic adapter with no preset: the
        | wire format is REST-by-convention (the endpoint resolver maps a
        | model's $table verbatim to /{table} and /{table}/{id}), and the
        | capability block declares only what Petstore actually offers.
        |
        | Petstore has NO collection filtering, sorting, or pagination on its
        | REST resources (its "findByStatus"/"findByTags" are RPC endpoints,
        | not query params), so those capabilities are absent and any such
        | query fails loudly at the call site. What works: find($id) ->
        | GET /pet/{id}, all()/get() -> GET /pet, and writes.
        |
        | Note: Petstore fakes/regularly-resets writes, so the write demo is
        | safe to run repeatedly.
        */
        'petstore' => [
            'driver' => 'restdb',
            'adapter' => 'generic',
            'base_url' => env('PETSTORE_API_URL', 'https://petstore3.swagger.io/api/v3'),
            'auth' => ['driver' => 'none'],
            // Petstore's JSON uses camelCase members (photoUrls, userStatus) and
            // the generic adapter sends attribute names verbatim — no snake<->
            // camel translation. So the models are generated with
            // name_mapping=none: columns stay camelCase and match the wire 1:1.
            // (restdb:make-openapi-models reads this key at generation time.)
            'name_mapping' => 'none',
            // No 'response.data' path — Petstore returns bare JSON, so the body
            // itself is the payload (an object is one row, an array is rows).
            'capabilities' => [
                'select' => true,
                // Eloquent's find()/first() append limit(1); the driver gates
                // that on page.limit. On an identity read the where(id) targets
                // GET /pet/{id} and the limit never reaches the wire, so this is
                // declared to let find()/first() work — Petstore has no real
                // _limit param and simply ignores unknown query keys.
                'page.limit' => true,
                'write.insert' => true,
                'write.update' => true,
                'write.delete' => true,
            ],
            'http' => ['timeout' => 10, 'connect_timeout' => 5],
        ],

    ],

];
