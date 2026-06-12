<?php

declare(strict_types=1);

namespace App\RestDB;

use Vitis\RestDB\Contracts\ResponseParser;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\ErrorBag;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\ResultPage;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;
use Vitis\RestDB\Values\WriteResult;

/**
 * json-server bodies are bare: a JSON array for collections, a JSON object
 * for single resources and write echoes. No envelope, no error documents.
 */
final class JsonPlaceholderParser implements ResponseParser
{
    public function rows(ApiResponse $response, SelectIntent $intent): ResultPage
    {
        $json = $response->json();

        $rows = [];

        foreach (array_is_list($json) ? $json : [$json] as $row) {
            if (is_array($row) && $row !== []) {
                $rows[] = $row;
            }
        }

        return new ResultPage($rows);
    }

    public function writeResult(ApiResponse $response, InsertIntent|UpdateIntent|DeleteIntent $intent): WriteResult
    {
        $json = $response->json();
        $attributes = array_is_list($json) ? [] : $json;
        $id = $attributes['id'] ?? null;

        return new WriteResult(
            affected: $response->successful() ? 1 : 0,
            id: is_int($id) || is_string($id) ? $id : null,
            attributes: $attributes,
        );
    }

    public function errors(ApiResponse $response): ?ErrorBag
    {
        return null; // json-server has no error documents — status mapping covers it
    }
}
