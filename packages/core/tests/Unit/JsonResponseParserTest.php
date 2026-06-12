<?php

declare(strict_types=1);

use Vitis\RestDB\Rest\JsonResponseParser;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\ConnectionConfig;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\SelectIntent;

function jsonParser(array $config = []): JsonResponseParser
{
    return new JsonResponseParser(new ConnectionConfig('test', $config));
}

function jsonResponse(string $body, int $status = 200): ApiResponse
{
    return new ApiResponse($status, [], $body);
}

it('parses a bare JSON list as rows and a bare object as one row', function () {
    $intent = new SelectIntent('articles');

    expect(jsonParser()->rows(jsonResponse('[{"id":1},{"id":2}]'), $intent)->rows)
        ->toBe([['id' => 1], ['id' => 2]])
        ->and(jsonParser()->rows(jsonResponse('{"id":1,"title":"x"}'), $intent)->rows)
        ->toBe([['id' => 1, 'title' => 'x']])
        ->and(jsonParser()->rows(jsonResponse('[]'), $intent)->rows)->toBe([])
        ->and(jsonParser()->rows(jsonResponse('{}'), $intent)->rows)->toBe([]);
});

it('unwraps an envelope at the configured data path', function () {
    $parser = jsonParser(['response' => ['data' => 'result.items']]);

    expect($parser->rows(jsonResponse('{"result":{"items":[{"id":1}]},"meta":{}}'), new SelectIntent('articles'))->rows)
        ->toBe([['id' => 1]])
        ->and($parser->rows(jsonResponse('{"unexpected":true}'), new SelectIntent('articles'))->rows)
        ->toBe([]);
});

it('reads write echoes through the data path and the configured id key', function () {
    $bare = jsonParser()->writeResult(jsonResponse('{"id":101,"title":"x"}', 201), new InsertIntent('articles', [[]]));
    $custom = jsonParser(['response' => ['data' => 'data'], 'id_key' => 'uuid'])
        ->writeResult(jsonResponse('{"data":{"uuid":"abc","title":"x"}}', 201), new InsertIntent('articles', [[]]));

    expect($bare->affected)->toBe(1)
        ->and($bare->id)->toBe(101)
        ->and($bare->attributes)->toBe(['id' => 101, 'title' => 'x'])
        ->and($custom->id)->toBe('abc');
});

it('returns no errors without an errors path, and maps shapes when one is set', function () {
    $silent = jsonParser();
    $parser = jsonParser(['response' => ['errors' => 'errors']]);

    expect($silent->errors(jsonResponse('{"errors":{"title":["required"]}}', 422)))->toBeNull()
        ->and($parser->errors(jsonResponse('{"data":[]}')))->toBeNull()
        ->and($parser->errors(jsonResponse('{"errors":{"title":["required","too short"]}}', 422))?->fieldMessages)
        ->toBe(['title' => ['required', 'too short']])
        ->and($parser->errors(jsonResponse('{"errors":["boom"]}', 500))?->general)->toBe(['boom'])
        ->and(jsonParser(['response' => ['errors' => 'message']])
            ->errors(jsonResponse('{"message":"boom"}', 500))?->general)->toBe(['boom']);
});
