<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\JsonApi\Post;
use Vitis\RestDB\Exceptions\ApiValidationException;

beforeEach(function () {
    $this->defineJsonApiConnection();
});

it('creates via POST with a typed resource document', function () {
    Http::fake(['*' => Http::response(['data' => [
        'type' => 'posts', 'id' => '7',
        'attributes' => ['title' => 'Hello', 'viewCount' => 0],
    ]], 201)]);

    $post = new Post(['title' => 'Hello', 'view_count' => 0]);

    expect($post->save())->toBeTrue()
        ->and($post->id)->toBe('7')
        ->and($post->view_count)->toBe(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://jsonapi.test/posts'
        && $request->hasHeader('Content-Type', 'application/vnd.api+json')
        && $request->data() === ['data' => [
            'type' => 'posts',
            'attributes' => ['title' => 'Hello', 'viewCount' => 0],
        ]]);
});

it('PATCHes dirty attributes only, typed and identified', function () {
    $post = (new Post)->newFromBuilder(['id' => '7', 'title' => 'Hello', 'status' => 'draft']);

    Http::fake(['*' => Http::response(['data' => [
        'type' => 'posts', 'id' => '7',
        'attributes' => ['title' => 'Hello!', 'status' => 'draft'],
    ]])]);

    \assert($post instanceof Post);
    $post->title = 'Hello!';

    expect($post->save())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'https://jsonapi.test/posts/7'
        && $request->data() === ['data' => [
            'type' => 'posts',
            'id' => '7',
            'attributes' => ['title' => 'Hello!'],
        ]]);
});

it('DELETEs the resource URL', function () {
    $post = (new Post)->newFromBuilder(['id' => '7', 'title' => 'Hello']);

    Http::fake(['*' => Http::response(null, 204)]);

    \assert($post instanceof Post);
    expect($post->delete())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://jsonapi.test/posts/7');
});

it('maps error-document pointers to snake_case fields', function () {
    Http::fake(['*' => Http::response(['errors' => [
        [
            'status' => '422',
            'detail' => 'View count must be positive.',
            'source' => ['pointer' => '/data/attributes/viewCount'],
        ],
    ]], 422)]);

    $post = new Post(['title' => 'x', 'view_count' => -1]);

    try {
        $post->save();
        $this->fail('Expected ApiValidationException.');
    } catch (ApiValidationException $e) {
        expect($e->errors())->toHaveKey('view_count')
            ->and($e->errors()['view_count'][0])->toBe('View count must be positive.');
    }
});
