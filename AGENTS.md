# Agent Guidelines for graphql-laravel-select-fields

SelectFields (Eloquent eager loading) for `rebing/graphql-laravel`. PHP 8.2+, Laravel 12+.

Optimizes GraphQL queries by analysing the requested field selection to build
minimal Eloquent `SELECT` columns and eager-load only the needed relations.

For development setup, commands, code style, and the PR workflow, see [CONTRIBUTING.md](CONTRIBUTING.md).

## Project Structure

```
src/
└── Support/
    ├── SelectFields.php                  # Core class: field/relation analysis, SQL optimization
    ├── SelectFieldsServiceProvider.php   # Service provider: registers injector, pagination overrides
    ├── SelectFieldsParameterInjector.php # ResolverParameterInjector: DI for Closure and SelectFields
    ├── Contracts/
    │   └── WrapType.php                  # Marker interface for wrapper type traversal
    └── SelectFields/
        ├── PaginationType.php            # SelectFields-aware pagination (extends core)
        ├── SimplePaginationType.php
        └── CursorPaginationType.php

tests/
├── TestCase.php              # Base: extends Orchestra Testbench, no DB
├── TestCaseDatabase.php      # Base: adds SQLite in-memory DB + migrations
├── Database/
│   ├── SelectFieldsTest.php  # Top-level SelectFields DI and type-wrapping tests
│   └── SelectFields/         # 16 subdirectories of feature tests
│       ├── SelectFieldsTestCase.php  # Base for all SelectFields tests (registers injector)
│       ├── AlwaysTests/
│       ├── AlwaysRelationTests/
│       ├── AliasedRelationArgTests/
│       ├── ArrayTests/
│       ├── ComputedPropertiesTests/
│       ├── DepthTests/
│       ├── InterfaceTests/
│       ├── LazyTypeTests/
│       ├── MorphRelationshipTests/
│       ├── NestedRelationLoadingTests/
│       ├── PrimaryKeyTests/
│       ├── QueryArgsAndContextTests/
│       ├── UnionTests/
│       ├── ValidateDiffNodeTests/
│       ├── ValidateFieldTests/
│       └── WrapTypeTests/
└── Support/                  # Shared fixtures
    ├── Models/               # User, Post, Comment, Like
    ├── Objects/              # TestCase schema fixtures
    ├── Queries/              # SelectFields query fixtures
    ├── Types/                # SelectFields type fixtures
    ├── Traits/               # SqlAssertionTrait
    └── database/             # Migrations, factories
```

## Architecture

### Relationship to rebing/graphql-laravel

This package shares the `Rebing\GraphQL\` namespace with the parent package.
Both packages map `Rebing\GraphQL\` to their own `src/` directories. Composer
merges the autoload rules. No class name collisions exist.

The package depends on `rebing/graphql-laravel` for:
- `Field` class (resolver DI, `registerParameterInjector()`)
- `ResolverParameterInjector` interface (in `Support\Contracts`)
- Base pagination types (`PaginationType`, `SimplePaginationType`, `CursorPaginationType`)
- `GraphQL` facade (`paginate()`, `simplePaginate()`, `cursorPaginate()`)

### Key Classes

- **`SelectFields`** (`src/Support/SelectFields.php`): Core class. Analyses the GraphQL query plan to produce `getSelect()` (column list) and `getRelations()` (eager-load closures) for Eloquent.
- **`SelectFieldsParameterInjector`** (`src/Support/SelectFieldsParameterInjector.php`): Implements `ResolverParameterInjector`. Supports `Closure $getSelectFields` (lazy) and `SelectFields $fields` (eager) injection patterns.
- **`SelectFieldsServiceProvider`** (`src/Support/SelectFieldsServiceProvider.php`): Registers the injector and overrides pagination types with SelectFields-aware subclasses.
- **`WrapType`** (`src/Support/Contracts/WrapType.php`): Marker interface. Tells SelectFields to transparently traverse wrapper types (pagination, custom wrappers).

### Field Configuration Keys (consumed by SelectFields)

| Key | Default | Purpose |
|-----|---------|---------|
| `selectable` | `true` | Include field in SQL SELECT |
| `is_relation` | `true` | Treat sub-fields as Eloquent relation |
| `always` | - | Extra columns always selected |
| `query` | - | Custom Eloquent query on eager-loaded relation |
| `alias` | field name | Map field to different column/relation |
| `model` | - | Eloquent model class (type-level attribute) |

## Test Conventions

### Base Classes

- **`SelectFieldsTestCase`** (`tests/Database/SelectFields/SelectFieldsTestCase.php`): Extends `TestCaseDatabase`. Registers `SelectFieldsParameterInjector`, configures SelectFields-aware pagination types.
- **`TestCaseDatabase`** (`tests/TestCaseDatabase.php`): Adds SQLite in-memory DB + migrations.
- **`TestCase`** (`tests/TestCase.php`): Extends Orchestra Testbench. Configures base schemas.

### Writing Tests

- Extend `SelectFieldsTestCase` for all new SelectFields tests.
- Override `getEnvironmentSetUp($app)` to register test-specific schemas and types (call `parent::getEnvironmentSetUp($app)` first).
- Co-locate test-specific support classes (queries, types) in the same directory as the test.
- Use `SqlAssertionTrait` to verify SQL query count and content.

### Models and Factories

Tests use 4 Eloquent models in `tests/Support/Models/`: `User`, `Post`, `Comment`, `Like` with corresponding factories and migrations in `tests/Support/database/`.
