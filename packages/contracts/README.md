# vitis/restdb-contracts

The SPI for the [RestDB Eloquent driver](https://github.com/vitis/restdb):
contracts, immutable value objects, and capability primitives. Depends on
nothing from the driver — implement these to build an adapter.

- **Contracts** — `Adapter`, `RequestCompiler`, `ResponseParser`, `Paginator`,
  `Authenticator`/`RefreshableAuthenticator`, `ResolvesEndpoints`,
  `FilterDialect`, `SpecParser`, and the `RestDBException` marker.
- **Values** — intents (`SelectIntent`, `InsertIntent`, `UpdateIntent`,
  `DeleteIntent`), filters (`FilterGroup`, `Condition`), paging
  (`PageRequest`, `PageInfo`), wire shapes (`CompiledRequest`, `ApiResponse`,
  `ResultPage`, `WriteResult`, `ErrorBag`, `EmptyResult`), and
  `ConnectionConfig`.
- **Capabilities** — the `Capability` and `Operator` enums, the immutable
  `CapabilitySet`, and the `CapabilityGate` whose exceptions name the
  connection, capability, model, method, and fix.

An adapter is a factory of strategies — compiler, parser, paginator, endpoint
resolver, capability baseline. Validate yours against
`Vitis\RestDB\Testing\AdapterConformanceKit` (shipped with `vitis/restdb`).
