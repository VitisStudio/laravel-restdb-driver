<?php

declare(strict_types=1);

namespace Vitis\RestDB\Rest;

use Vitis\RestDB\Contracts\ResponseParser;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\ConnectionConfig;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\ErrorBag;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\ResultPage;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;
use Vitis\RestDB\Values\WriteResult;

/**
 * The generic adapter's default parser: plain JSON bodies, optionally inside
 * an envelope.
 *
 *   response.data    dot path to the payload ('data', 'result.items');
 *                    unset = the body IS the payload (json-server style)
 *   response.errors  dot path to an error document; unset = the API has no
 *                    error bodies and status mapping covers it
 *   id_key           write-echo primary key, default 'id'
 *
 * A JSON list parses as rows; a JSON object parses as one row.
 */
final class JsonResponseParser implements ResponseParser
{
    private readonly ?string $dataPath;

    private readonly ?string $errorsPath;

    private readonly string $idKey;

    public function __construct(ConnectionConfig $config)
    {
        $dataPath = $config->get('response.data');
        $this->dataPath = is_string($dataPath) && $dataPath !== '' ? $dataPath : null;

        $errorsPath = $config->get('response.errors');
        $this->errorsPath = is_string($errorsPath) && $errorsPath !== '' ? $errorsPath : null;

        $idKey = $config->get('id_key');
        $this->idKey = is_string($idKey) && $idKey !== '' ? $idKey : 'id';
    }

    public function rows(ApiResponse $response, SelectIntent $intent): ResultPage
    {
        $payload = $this->payload($response);

        $rows = [];

        foreach (array_is_list($payload) ? $payload : [$payload] as $row) {
            if (is_array($row) && $row !== []) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return new ResultPage($rows);
    }

    public function writeResult(ApiResponse $response, InsertIntent|UpdateIntent|DeleteIntent $intent): WriteResult
    {
        $payload = $this->payload($response);
        $attributes = array_is_list($payload) ? [] : $payload;
        $id = $attributes[$this->idKey] ?? null;

        /** @var array<string, mixed> $attributes */
        return new WriteResult(
            affected: $response->successful() ? 1 : 0,
            id: is_int($id) || is_string($id) ? $id : null,
            attributes: $attributes,
        );
    }

    public function errors(ApiResponse $response): ?ErrorBag
    {
        if ($this->errorsPath === null) {
            return null;
        }

        $errors = self::extract($response->json(), $this->errorsPath);

        if ($errors === null || $errors === [] || $errors === '') {
            return null;
        }

        if (is_string($errors)) {
            return new ErrorBag(general: [$errors]);
        }

        if (! is_array($errors)) {
            return null;
        }

        // Laravel-style {"field": ["msg", ...]} maps to field messages;
        // anything stringy at the top level is a general error.
        $fields = [];
        $general = [];

        foreach ($errors as $key => $value) {
            $messages = array_values(array_filter(
                is_array($value) ? $value : [$value],
                is_string(...),
            ));

            if ($messages === []) {
                continue;
            }

            if (is_string($key)) {
                $fields[$key] = $messages;
            } else {
                $general = [...$general, ...$messages];
            }
        }

        $bag = new ErrorBag($fields, $general);

        return $bag->any() ? $bag : null;
    }

    /** @return array<mixed> */
    private function payload(ApiResponse $response): array
    {
        $json = $response->json();

        if ($this->dataPath === null) {
            return $json;
        }

        $payload = self::extract($json, $this->dataPath);

        return is_array($payload) ? $payload : [];
    }

    /** @param array<mixed> $json */
    private static function extract(array $json, string $path): mixed
    {
        $value = $json;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
