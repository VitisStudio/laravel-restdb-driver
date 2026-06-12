<?php

declare(strict_types=1);
use App\RestDB\JsonPlaceholderCompiler;
use App\RestDB\JsonPlaceholderPaginator;
use App\RestDB\JsonPlaceholderParser;

return [

    'default' => 'jsonplaceholder',

    'connections' => [

        /*
        | JSONPlaceholder is plain REST (json-server), not JSON:API — so this
        | connection uses the generic adapter with a hand-written compiler,
        | parser, and paginator (~150 lines total in app/RestDB). The
        | capability block declares exactly what json-server can honor;
        | everything else fails loudly at the call site.
        */
        'jsonplaceholder' => [
            'driver' => 'restdb',
            'adapter' => 'generic',
            'base_url' => 'https://jsonplaceholder.typicode.com',
            'auth' => ['driver' => 'none'],
            'compiler' => JsonPlaceholderCompiler::class,
            'parser' => JsonPlaceholderParser::class,
            'paginator' => JsonPlaceholderPaginator::class,
            'capabilities' => [
                'select' => true,
                'filter' => ['operators' => ['eq', 'in', 'ne', 'gte', 'lte', 'like']],
                'sort' => true,
                'sort.multi' => true,
                'aggregate.count' => true,
                'aggregate.exists' => true,
                'write.insert' => true,
                'write.update' => true,
                'write.delete' => true,
                // page.limit / page.number / page.offset / page.total are
                // contributed by JsonPlaceholderPaginator::provides().
            ],
            'http' => ['timeout' => 10, 'connect_timeout' => 5],
        ],

        /*
        | Reference: what a real JSON:API backend looks like with the
        | preconfigured vitis/restdb-jsonapi adapter — zero custom classes.
        | Point base_url at a JSON:API v1.1 server and it works as-is.
        */
        'crm' => [
            'driver' => 'restdb',
            'adapter' => 'json-api',
            'base_url' => env('CRM_API_URL', 'https://example.invalid'),
            'pagination' => ['strategy' => 'page-number', 'size' => 25, 'meta_total' => 'meta.page.total'],
            'filter_dialect' => 'nested-operator',
            'capabilities' => ['page.total' => true, 'aggregate.count' => true],
        ],

    ],

];
