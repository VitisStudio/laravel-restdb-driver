<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

/** Returned by a compiler when a query provably matches nothing — no HTTP is issued. */
final class EmptyResult {}
