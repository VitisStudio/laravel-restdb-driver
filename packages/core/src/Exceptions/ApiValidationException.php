<?php

declare(strict_types=1);

namespace Vitis\RestDB\Exceptions;

use Illuminate\Validation\ValidationException;
use Vitis\RestDB\Contracts\RestDBException;
use Vitis\RestDB\Values\ErrorBag;

/**
 * A 422 from the API, mapped to Laravel's native validation exception so the
 * framework's handler renders it exactly like local validation failures.
 */
final class ApiValidationException extends ValidationException implements RestDBException
{
    public static function fromErrorBag(string $connection, ?ErrorBag $errors): self
    {
        $messages = $errors === null ? [] : $errors->fieldMessages;

        if ($messages === []) {
            $general = $errors === null ? [] : $errors->general;
            $messages = ['*' => $general === [] ? ["Connection [{$connection}]: the API rejected the request as unprocessable (422)."] : $general];
        }

        return self::withMessages($messages);
    }
}
