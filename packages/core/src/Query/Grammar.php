<?php

declare(strict_types=1);

namespace Vitis\RestDB\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;
use LogicException;
use ReflectionClass;

/**
 * Stub grammar: every compile* throws. Guards against any stray SQL path —
 * the restdb driver is read/written through intents, never SQL.
 */
final class Grammar extends BaseGrammar
{
    public function __construct(Connection $connection)
    {
        // Laravel 12 grammars take the connection in the constructor; Laravel 11
        // grammars have no constructor. Support both without a version matrix.
        $constructor = (new ReflectionClass(BaseGrammar::class))->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfParameters() > 0) {
            parent::__construct($connection);
        }
    }

    public function compileSelect(BaseBuilder $query): never
    {
        $this->sqlPath('compileSelect');
    }

    /** @param  array<mixed>  $values */
    public function compileInsert(BaseBuilder $query, array $values): never
    {
        $this->sqlPath('compileInsert');
    }

    /** @param  array<mixed>  $values */
    public function compileUpdate(BaseBuilder $query, array $values): never
    {
        $this->sqlPath('compileUpdate');
    }

    public function compileDelete(BaseBuilder $query): never
    {
        $this->sqlPath('compileDelete');
    }

    public function compileExists(BaseBuilder $query): never
    {
        $this->sqlPath('compileExists');
    }

    public function compileTruncate(BaseBuilder $query): never
    {
        $this->sqlPath('compileTruncate');
    }

    private function sqlPath(string $method): never
    {
        throw new LogicException(
            "Grammar::{$method}() was reached on a restdb connection — there is no SQL grammar. "
            .'This is a stray SQL path; the query should have been compiled to a REST request. '
            .'Use toRequest() instead of toSql().',
        );
    }
}
