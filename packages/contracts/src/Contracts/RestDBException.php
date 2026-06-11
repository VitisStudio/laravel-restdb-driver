<?php

declare(strict_types=1);

namespace Vitis\RestDB\Contracts;

use Throwable;

/**
 * Marker for every exception this driver throws on purpose. RestConnection
 * rethrows these untouched instead of wrapping them in QueryException.
 */
interface RestDBException extends Throwable {}
