# Mock JSON:API server

A real JSON:API server for exercising the `vitis/restdb-jsonapi` adapter,
powered by [hatchify](https://github.com/bitovi/hatchify) (`@hatchifyjs/koa`)
over an in-memory SQLite database. Seeds a small object graph on boot:

```
3 authors ── 4 posts each ── 2 comments each
```

```bash
npm install
npm start            # http://localhost:3010/api  (PORT env to change)
```

Then, from `examples/jsonplaceholder`:

```bash
php artisan demo:crm
php artisan restdb:capabilities crm
```

## Why hatchify

It is a *strict, opinionated* JSON:API implementation, which makes it a good
adapter workout — three adapter features exist because of it:

| Hatchify behavior | Adapter answer |
| --- | --- |
| Operators are dollar-prefixed: `filter[rating][$gte]=4` | `'filter_dialect' => 'dollar-operator'` |
| Writes demand the schema name as `type` (`Author`, not `authors`) | `'resource_types' => ['authors' => 'Author']` |
| No `links` object; totals in `meta.unpaginatedCount` | total-based pagination math + `'meta_total' => 'meta.unpaginatedCount'` |

Data is in-memory — restarting the server reseeds from scratch, so write
demos are repeatable.
