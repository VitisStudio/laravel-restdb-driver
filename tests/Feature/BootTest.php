<?php

declare(strict_types=1);
use Vitis\RestDB\JsonApi\JsonApiServiceProvider;
use Vitis\RestDB\RestDBServiceProvider;

it('boots the testbench app with both providers registered', function () {
    expect($this->app->getProviders(RestDBServiceProvider::class))->not->toBeEmpty()
        ->and($this->app->getProviders(JsonApiServiceProvider::class))->not->toBeEmpty();
});

it('merges the package config', function () {
    expect(config('restdb.guards.max_pages'))->toBe(50)
        ->and(config('restdb.guards.where_has_max_keys'))->toBe(500)
        ->and(config('restdb.http.timeout'))->toBe(10);
});
