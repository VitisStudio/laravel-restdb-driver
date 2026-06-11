<?php

declare(strict_types=1);

namespace Vitis\RestDB\Contracts;

use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\ErrorBag;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\ResultPage;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;
use Vitis\RestDB\Values\WriteResult;

interface ResponseParser
{
    /** Decode the body into flat attribute rows + pagination-relevant meta. */
    public function rows(ApiResponse $response, SelectIntent $intent): ResultPage;

    /** Server-side resource state after a write (may differ from what was sent). */
    public function writeResult(ApiResponse $response, InsertIntent|UpdateIntent|DeleteIntent $intent): WriteResult;

    /** Null when the body is not an error document. */
    public function errors(ApiResponse $response): ?ErrorBag;
}
