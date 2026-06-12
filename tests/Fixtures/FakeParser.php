<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Vitis\RestDB\Contracts\ResponseParser;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\ErrorBag;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\ResultPage;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;
use Vitis\RestDB\Values\WriteResult;

/** Reads the common {"data": [...]} envelope; count queries read {"count": n}. */
final class FakeParser implements ResponseParser
{
    public function rows(ApiResponse $response, SelectIntent $intent): ResultPage
    {
        $json = $response->json();

        if ($intent->aggregate === 'count') {
            $count = $json['count'] ?? 0;

            return new ResultPage([['aggregate' => is_numeric($count) ? (int) $count : 0]]);
        }

        $data = $json['data'] ?? [];
        $meta = $json['meta'] ?? [];

        /** @var list<array<string, mixed>> $data */
        $data = is_array($data) ? array_values($data) : [];

        return new ResultPage($data, is_array($meta) ? $meta : []);
    }

    public function writeResult(ApiResponse $response, InsertIntent|UpdateIntent|DeleteIntent $intent): WriteResult
    {
        $data = $response->json()['data'] ?? [];

        /** @var array<string, mixed> $attributes */
        $attributes = is_array($data) && ! array_is_list($data) ? $data : [];

        $id = $attributes['id'] ?? null;

        return new WriteResult(
            affected: $response->successful() ? 1 : 0,
            id: is_string($id) || is_int($id) ? $id : null,
            attributes: $attributes,
        );
    }

    public function errors(ApiResponse $response): ?ErrorBag
    {
        $json = $response->json();

        $fields = [];

        foreach (is_array($json['errors'] ?? null) ? $json['errors'] : [] as $field => $messages) {
            if (is_string($field) && is_array($messages)) {
                $fields[$field] = array_values(array_filter($messages, is_string(...)));
            }
        }

        $message = $json['message'] ?? null;

        if ($fields === [] && ! is_string($message)) {
            return null;
        }

        return new ErrorBag($fields, is_string($message) ? [$message] : []);
    }
}
