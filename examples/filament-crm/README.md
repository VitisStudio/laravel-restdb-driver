# Example: Filament v5 admin panel over a REST API

A Filament v5 admin panel whose "database" is the hatchify mock JSON:API
server in [tools/mock-jsonapi](../../tools/mock-jsonapi) — every table, form,
filter, and relation manager you see is HTTP round trips through the restdb
driver, not SQL. Only the framework's own tables (users, sessions, cache)
live in a local SQLite file.

```bash
# terminal 1 — the API
cd tools/mock-jsonapi && npm install && npm start

# terminal 2 — the panel
cd examples/filament-crm
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan make:filament-user   # any credentials you like
php artisan serve
# log in at http://localhost:8000/admin
```

## What it demonstrates

Three resources (`Author`, `Post`, `Comment`) with **full CRUD**, built on
the plain Eloquent models in `app/Models/Crm` — the same `crm` connection
config as the console example:

- **Tables**: pagination (page-number params + `meta.unpaginatedCount`
  totals), column sorting (`sort=-rating`), a multi-select rating filter
  that compiles to `filter[rating][$in]=…` — every interaction is one API
  request.
- **Relation managers on view pages**: an author's posts and a post's
  comments, scoped through the relationship (`filter[authorId]=…`), with
  create/edit/delete enabled (`isReadOnly(): false`).
- **Forms**: `Select::relationship()` options loaded from the API, writes as
  typed JSON:API documents — POST on create, dirty-only PATCH on save,
  DELETE with confirmation.
- **Relationship columns** (`author.name`) ride compound documents
  (`include=author`) — zero extra requests.

## What the driver had to learn for Filament

Filament exercises Eloquent more aggressively than hand-written code, which
forced two core fixes (both shipped in `vitis/restdb`):

1. `toBase()->getCountForPagination()` — Filament counts before paginating;
   the driver now answers it through the same one-request count emulation as
   `count()`.
2. Relation objects on unsaved models — Filament instantiates `author()` on
   an empty `Post` just to read relationship metadata, which compiles a
   `whereNull` constraint on a query that never executes. Null-operator
   gating moved from clause time to intent-build time: still throws before
   any HTTP, but construction is free.

## Honest limitations

- **No search.** Filament search compiles to an OR group across columns;
  the dollar-operator dialect has no OR syntax, so search would throw — it
  is disabled (`canGloballySearch(): false`, no `searchable()` columns)
  rather than silently wrong.
- The relation managers' *Create* buttons link to the related resource's
  create page (Filament's behavior when a relation manager declares
  `$relatedResource`), so the foreign key is chosen in the form rather than
  preset.
