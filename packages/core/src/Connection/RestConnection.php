<?php

declare(strict_types=1);

namespace Vitis\RestDB\Connection;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use LogicException;
use Vitis\RestDB\Capabilities\CapabilityGate;
use Vitis\RestDB\Capabilities\CapabilitySet;
use Vitis\RestDB\Contracts\Paginator;
use Vitis\RestDB\Contracts\RequestCompiler;
use Vitis\RestDB\Contracts\ResponseParser;
use Vitis\RestDB\Contracts\RestDBException;
use Vitis\RestDB\Exceptions\ApiResponseException;
use Vitis\RestDB\Exceptions\RestDBAuthenticationException;
use Vitis\RestDB\Exceptions\ResultTruncationException;
use Vitis\RestDB\Http\Transport;
use Vitis\RestDB\Query\Builder;
use Vitis\RestDB\Query\Grammar;
use Vitis\RestDB\Query\Processor;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\ConnectionConfig;
use Vitis\RestDB\Values\EmptyResult;
use Vitis\RestDB\Values\SelectIntent;

/**
 * Owns no API knowledge — sequences contracts: compile → page → send → parse →
 * drain. Anything the base Connection implements via PDO is explicitly blocked:
 * read-only-by-design, never read-only-by-omission.
 */
class RestConnection extends Connection
{
    private readonly CapabilityGate $capabilityGate;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly ConnectionConfig $connectionConfig,
        private readonly RequestCompiler $compiler,
        private readonly ResponseParser $parser,
        private readonly Paginator $paginator,
        private readonly CapabilitySet $capabilitySet,
        private readonly Transport $transport,
        array $config = [],
    ) {
        parent::__construct(
            static fn () => throw new LogicException('The restdb driver never opens a PDO connection.'),
            $connectionConfig->name,
            '',
            $config,
        );

        $this->capabilityGate = new CapabilityGate($capabilitySet, $connectionConfig->name);
    }

    /*
    |--------------------------------------------------------------------------
    | The single chokepoint
    |--------------------------------------------------------------------------
    |
    | Model::newBaseQueryBuilder() calls getConnection()->query(), so every
    | model on this connection gets the gated builder with zero model config.
    |
    */

    public function query(): Builder
    {
        return new Builder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    public function table($table, $as = null): Builder
    {
        if (! is_string($table)) {
            throw new LogicException('RestConnection::table() requires a string resource name.');
        }

        return $this->query()->from($as === null ? $table : "{$table} as {$as}");
    }

    public function capabilities(): CapabilitySet
    {
        return $this->capabilitySet;
    }

    public function gate(): CapabilityGate
    {
        return $this->capabilityGate;
    }

    public function connectionConfig(): ConnectionConfig
    {
        return $this->connectionConfig;
    }

    /** Compile an intent without executing it — powers Builder::toRequest(). */
    public function compile(SelectIntent $intent): CompiledRequest|EmptyResult
    {
        $compiled = $this->compiler->compileSelect($intent);

        if ($compiled instanceof EmptyResult) {
            return $compiled;
        }

        return $this->paginator->applyPage($compiled, $intent->page);
    }

    /*
    |--------------------------------------------------------------------------
    | Execution
    |--------------------------------------------------------------------------
    */

    /**
     * @param  SelectIntent|string  $query
     * @param  array<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if (! $query instanceof SelectIntent) {
            throw new LogicException('RestConnection::select() expects a SelectIntent — raw SQL has no meaning here.');
        }

        if ($query->provablyEmpty()) {
            return [];
        }

        $compiled = $this->compile($query);

        if ($compiled instanceof EmptyResult) {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return $this->run($compiled->requestLine(), [], function () use ($query, $compiled): array {
            return $this->drain($compiled, $query);
        });
    }

    /** @return list<array<string, mixed>> */
    private function drain(CompiledRequest $request, SelectIntent $intent): array
    {
        $maxPages = $this->maxPages();
        $rows = [];
        $pages = 0;

        do {
            if (++$pages > $maxPages) {
                throw ResultTruncationException::maxPages($this->connectionConfig->name, $maxPages);
            }

            $response = $this->sendAndMap($request);

            if ($response === null) {
                break;
            }

            $page = $this->parser->rows($response, $intent);
            $rows = array_merge($rows, $page->rows);

            if ($intent->page?->limit !== null && count($rows) >= $intent->page->limit) {
                break;
            }

            $info = $this->paginator->pageInfo($response, $page);
        } while (($request = $this->paginator->nextRequest($request, $info)) !== null);

        if ($intent->page?->limit !== null) {
            $rows = array_slice($rows, 0, $intent->page->limit);
        }

        return $rows;
    }

    /** Null = 404, which reads as "no matching resource" — zero rows, not an error. */
    private function sendAndMap(CompiledRequest $request): ?ApiResponse
    {
        $response = $this->transport->send($request);

        if ($response->successful()) {
            return $response;
        }

        if ($response->status === 404) {
            return null;
        }

        if ($response->status === 401) {
            throw RestDBAuthenticationException::unauthorized($this->connectionConfig->name);
        }

        throw ApiResponseException::fromResponse(
            $this->connectionConfig->name,
            $request,
            $response,
            $this->parser->errors($response),
        );
    }

    private function maxPages(): int
    {
        $max = $this->connectionConfig->get('guards.max_pages', 50);

        return is_int($max) && $max > 0 ? $max : 50;
    }

    /**
     * Driver exceptions pass through untouched; everything else keeps the base
     * QueryException wrapping (with the request line in place of SQL).
     *
     * @param  string  $query
     * @param  array<mixed>  $bindings
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        try {
            return parent::runQueryCallback($query, $bindings, $callback);
        } catch (QueryException $e) {
            $previous = $e->getPrevious();

            if ($previous instanceof RestDBException) {
                throw $previous;
            }

            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Blocked surface — fail loud, never silently no-op
    |--------------------------------------------------------------------------
    */

    /**
     * Streams rows page by page — the recommended path for large sets: one
     * page in memory at a time, same max_pages guard, same limit semantics.
     *
     * @param  SelectIntent|string  $query
     * @param  array<mixed>  $bindings
     * @return \Generator<int, \stdClass>
     */
    public function cursor($query, $bindings = [], $useReadPdo = true): \Generator
    {
        if (! $query instanceof SelectIntent) {
            throw new LogicException('RestConnection::cursor() expects a SelectIntent — raw SQL has no meaning here.');
        }

        if ($query->provablyEmpty()) {
            return;
        }

        $compiled = $this->compile($query);

        if ($compiled instanceof EmptyResult) {
            return;
        }

        // run() wraps the stream setup so QueryExecuted still fires with the
        // request line; subsequent page fetches stream outside the timer.
        $this->run($compiled->requestLine(), [], static fn (): bool => true);

        $maxPages = $this->maxPages();
        $limit = $query->page?->limit;
        $request = $compiled;
        $pages = 0;
        $emitted = 0;

        do {
            if (++$pages > $maxPages) {
                throw ResultTruncationException::maxPages($this->connectionConfig->name, $maxPages);
            }

            $response = $this->sendAndMap($request);

            if ($response === null) {
                return;
            }

            $page = $this->parser->rows($response, $query);

            foreach ($page->rows as $row) {
                // The base contract streams stdClass rows, PDO-style.
                yield (object) $row;

                if ($limit !== null && ++$emitted >= $limit) {
                    return;
                }
            }

            $info = $this->paginator->pageInfo($response, $page);
        } while (($request = $this->paginator->nextRequest($request, $info)) !== null);
    }

    /** @param array<mixed> $bindings */
    public function insert($query, $bindings = []): never
    {
        throw new LogicException('The restdb write path ships in v0.3.');
    }

    /** @param array<mixed> $bindings */
    public function update($query, $bindings = []): never
    {
        throw new LogicException('The restdb write path ships in v0.3.');
    }

    /** @param array<mixed> $bindings */
    public function delete($query, $bindings = []): never
    {
        throw new LogicException('The restdb write path ships in v0.3.');
    }

    /** @param array<mixed> $bindings */
    public function statement($query, $bindings = []): never
    {
        throw new LogicException('Raw statements are not supported by the restdb driver.');
    }

    /** @param array<mixed> $bindings */
    public function affectingStatement($query, $bindings = []): never
    {
        throw new LogicException('Raw statements are not supported by the restdb driver.');
    }

    public function unprepared($query): never
    {
        throw new LogicException('Raw statements are not supported by the restdb driver.');
    }

    public function transaction(Closure $callback, $attempts = 1): never
    {
        $this->noTransactions('transaction');
    }

    public function beginTransaction(): never
    {
        $this->noTransactions('beginTransaction');
    }

    public function commit(): never
    {
        $this->noTransactions('commit');
    }

    public function rollBack($toLevel = null): never
    {
        $this->noTransactions('rollBack');
    }

    public function getSchemaBuilder(): never
    {
        throw new LogicException('REST APIs have no schema builder; the restdb driver cannot migrate them.');
    }

    protected function getDefaultQueryGrammar(): Grammar
    {
        return new Grammar($this);
    }

    protected function getDefaultPostProcessor(): Processor
    {
        return new Processor;
    }

    private function noTransactions(string $method): never
    {
        throw new LogicException(
            "{$method}() is not supported — REST APIs have no transactions. "
            .'Each request is its own atomic operation.',
        );
    }
}
