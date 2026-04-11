# Upgrade Guide

## Migrating from built-in SelectFields (graphql-laravel v9)

In graphql-laravel v10, `SelectFields` was extracted to this separate package.
If your application uses `SelectFields` for Eloquent query optimization, follow
these steps.

### Step 1: Install the package

```bash
composer require rebing/graphql-laravel-select-fields
```

### Step 2: Verify it works (zero code changes needed)

For most users, **no code changes are required**:

- **`Closure $getSelectFields` pattern** - works unchanged. The package
  registers a `ResolverParameterInjector` that handles the `Closure` type-hint
  exactly as before.

- **`SelectFields $fields` pattern** - works unchanged. The class remains at
  `Rebing\GraphQL\Support\SelectFields` - the same namespace as before.

- **`use Rebing\GraphQL\Support\SelectFields`** - works unchanged. No import
  changes needed.

- **Field configuration keys** - `model`, `selectable`, `is_relation`, `always`,
  `query`, and `alias` are all unchanged. SelectFields reads the same config
  format.

- **Pagination types** - the package automatically replaces the core pagination
  types with SelectFields-aware subclasses. `GraphQL::paginate()`,
  `GraphQL::simplePaginate()`, and `GraphQL::cursorPaginate()` all work as
  before.

### Removed Extension Points

The following methods were removed from `Rebing\GraphQL\Support\Field` in
graphql-laravel v10:

- `selectFieldClass()` - previously allowed overriding the SelectFields class.
- `instanciateSelectFields()` - previously constructed the SelectFields
  instance.

These are no longer needed. The package handles everything via the
`ResolverParameterInjector` interface. If you previously overrode these methods
to use a custom SelectFields subclass, you can now:

1. Extend `Rebing\GraphQL\Support\SelectFields` with your custom class.
2. Create a custom `ResolverParameterInjector` that returns your subclass.
3. Register it via `Field::registerParameterInjector()` in a service provider.

### Generated Stubs

Artisan generators (`make:graphql:query`, `make:graphql:mutation`) in
graphql-laravel v10 no longer include SelectFields boilerplate in the generated
code. When creating new queries or mutations that need SelectFields, add the
`Closure $getSelectFields` parameter manually - see the
[Quick Start](README.md#quick-start) section for the pattern.
