<?php

declare(strict_types=1);

namespace Vitis\RestDB\Contracts;

use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\EmptyResult;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;

interface RequestCompiler
{
    /** EmptyResult = query provably empty (whereIn []) — no HTTP is issued. */
    public function compileSelect(SelectIntent $intent): CompiledRequest|EmptyResult;

    public function compileInsert(InsertIntent $intent): CompiledRequest;

    /** Carries dirty attributes only. */
    public function compileUpdate(UpdateIntent $intent): CompiledRequest;

    public function compileDelete(DeleteIntent $intent): CompiledRequest;
}
