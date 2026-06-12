# vitis/restdb-jsonapi

A complete, preconfigured JSON:API v1.1 adapter for the
[RestDB Eloquent driver](https://github.com/vitis/restdb) — query compiler,
compound-document parser with an identity map, three pagination strategies,
pluggable filter dialects, and spec-driven model generation.

```php
'crm' => [
    'driver'   => 'restdb',
    'adapter'  => 'json-api',
    'base_url' => env('CRM_API_URL'),
    'pagination' => ['strategy' => 'page-number', 'size' => 50, 'meta_total' => 'meta.page.total'],
    'filter_dialect' => 'nested-operator',   // or 'comma-list', or your FilterDialect class
    'name_mapping' => 'camel',               // or 'kebab', 'none'
],
```

```php
class Article extends Model
{
    use \Vitis\RestDB\JsonApi\IsJsonApiResource;

    protected $connection = 'crm';
    protected $table = 'articles';
}
```

Highlights:

- `find(42)` → `GET /articles/42`; filters/sorts/sparse fieldsets compile to
  `filter[…]`, `sort`, `fields[type]` with name mapping.
- **`with('comments.author')` costs zero extra HTTP** — unconstrained eager
  loads ride `include=` and hydrate recursively from the compound document's
  identity map. Constrained closures fall back to real queries.
- Pagination follows `links.next` verbatim; totals come from your configured
  `meta_total` path and power one-request `paginate()` and `count()`.
- Writes are typed resource documents: `POST {data:{type,attributes}}`,
  dirty-only `PATCH`, `DELETE`; error-document pointers map to field-keyed
  validation messages.
- Honest capability baseline: only what the spec guarantees; filter operators
  come from the dialect's `supports()`.

Spec-driven tooling:

```bash
php artisan restdb:make-models crm --spec=storage/api-specs/crm.json \
    --path=app/Models/Crm --namespace="App\Models\Crm"
php artisan restdb:discover crm --spec=storage/api-specs/crm.json   # + --check in CI
```

Generated models are committed code you own — casts from schema types,
relations from spec relationships, never overwritten without `--force`.
