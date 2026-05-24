# SelectFields for graphql-laravel

[![Latest Stable Version](https://poser.pugx.org/rebing/graphql-laravel-select-fields/v/stable)](https://packagist.org/packages/rebing/graphql-laravel-select-fields)
[![License](https://poser.pugx.org/rebing/graphql-laravel-select-fields/license)](https://packagist.org/packages/rebing/graphql-laravel-select-fields)
[![Tests](https://github.com/rebing/graphql-laravel-select-fields/workflows/Tests/badge.svg)](https://github.com/rebing/graphql-laravel-select-fields/actions?query=workflow%3ATests)
[![Downloads](https://img.shields.io/packagist/dt/rebing/graphql-laravel-select-fields.svg?style=flat-square)](https://packagist.org/packages/rebing/graphql-laravel-select-fields)
[![Get on Slack](https://img.shields.io/badge/slack-join-orange.svg)](https://rebing-graphql.slack.com/join/shared_invite/enQtNTE5NjQzNDI5MzQ4LTdhNjk0ZGY1N2U1YjE4MGVlYmM2YTc2YjQ0MmIwODY5MWMwZWIwYmY1MWY4NTZjY2Q5MzdmM2Q3NTEyNDYzZjc#/shared-invite/email)

Optimizes GraphQL queries backed by Eloquent models. Analyzes the GraphQL
request's field selection to generate minimal `SELECT` columns and eager-load
only the requested relations - preventing N+1 queries and over-fetching.

This is an optional companion package for
[rebing/graphql-laravel](https://github.com/rebing/graphql-laravel).

## Requirements

- PHP ^8.2
- Laravel 12+
- rebing/graphql-laravel 10.0.0-RC4+

## Installation

```bash
composer require rebing/graphql-laravel-select-fields
```

The package auto-discovers its service provider. No manual registration is
needed. On boot it:

1. Registers a `ResolverParameterInjector` so that `Closure` and `SelectFields`
   type-hints in resolver methods work automatically.
2. Replaces the core pagination types with SelectFields-aware subclasses that
   implement `WrapType` and mark metadata fields as non-selectable.

## Quick Start

**1. Add `model` to your Type:**

```php
use App\Models\User;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class UserType extends GraphQLType
{
    protected $attributes = [
        'name'  => 'User',
        'model' => User::class,
    ];

    public function fields(): array
    {
        return [
            'id'    => ['type' => Type::nonNull(Type::id())],
            'email' => ['type' => Type::nonNull(Type::string())],
            'posts' => [
                'type' => Type::listOf(GraphQL::type('Post')),
            ],
        ];
    }
}
```

**2. Use `$getSelectFields` in your Query:**

```php
use Closure;
use App\Models\User;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;
use Rebing\GraphQL\Support\SelectFields;

class UsersQuery extends Query
{
    protected $attributes = [
        'name' => 'users',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('User'));
    }

    public function args(): array
    {
        return [
            'id'    => ['type' => Type::string()],
            'email' => ['type' => Type::string()],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $info, Closure $getSelectFields)
    {
        /** @var SelectFields $fields */
        $fields = $getSelectFields();
        $select = $fields->getSelect();
        $with = $fields->getRelations();

        return User::select($select)->with($with)->get();
    }
}
```

When a client queries `{ users { id email posts { title } } }`,
SelectFields generates:

```sql
SELECT "users"."id", "users"."email" FROM "users";
SELECT "posts"."title", "posts"."user_id" FROM "posts" WHERE "posts"."user_id" IN (?, ?);
```

Only the requested columns are fetched, and the `posts` relation is
eager-loaded in a single query.

## Usage

### Resolver Injection Patterns

SelectFields can be injected into your `resolve()` method in two ways.

#### Closure (lazy - recommended)

```php
public function resolve($root, array $args, $context, ResolveInfo $info, Closure $getSelectFields)
{
    /** @var SelectFields $fields */
    $fields = $getSelectFields();
    $select = $fields->getSelect();
    $with = $fields->getRelations();

    return User::select($select)->with($with)->get();
}
```

The `SelectFields` instance is only constructed when you call
`$getSelectFields()`. If your resolver has an early return path (cache hit,
authorization check), you avoid the cost of walking the query plan.

#### Class (eager)

```php
use Rebing\GraphQL\Support\SelectFields;

public function resolve($root, array $args, $context, ResolveInfo $info, SelectFields $fields)
{
    $select = $fields->getSelect();
    $with = $fields->getRelations();

    return User::select($select)->with($with)->get();
}
```

The `SelectFields` instance is constructed before your resolver runs.

### Type Configuration

#### The `model` Attribute

The `model` attribute on your Type's `$attributes` array is **required** for
SelectFields to work. It enables:

- Table-qualified column names (`"users"."id"` instead of `"id"`)
- Automatic primary key inclusion in SELECT
- Eloquent relation traversal for eager loading

```php
protected $attributes = [
    'name'  => 'User',
    'model' => User::class,
];
```

Without `model`, SelectFields operates in a degraded mode - no table
qualification, no primary key inclusion, no relation detection.

#### Field Configuration Keys

These keys can be placed in the arrays returned by your Type's `fields()` method:

| Key | Type | Default | Purpose |
|---|---|---|---|
| `selectable` | `bool` | `true` | Whether to include the field in SQL SELECT. Set to `false` for computed/virtual fields that have no database column (e.g. accessors). |
| `is_relation` | `bool` | `true` | Whether sub-fields represent an Eloquent relationship. Set to `false` for JSON columns or cast arrays. |
| `always` | `string\|string[]` | - | Additional columns always included in SELECT when this field is requested. Useful for computed properties that depend on other columns. |
| `query` | `Closure` | - | Custom query callback applied to the Eloquent eager-loading query for this relation. |
| `alias` | `string\|Closure\|Expression` | field name | Maps a GraphQL field name to a different database column or relation method name. |

### Eager Loading Relationships

The `profile` and `posts` relations must also exist on the User Eloquent model.
If some fields are required for the relation to load or for validation, you can
define an `always` attribute that will add the given attributes to select.

The attribute can be a comma separated string or an array of attributes to
always include.

```php
// Array form:
'always' => ['title', 'body'],
// String form (comma-separated):
'always' => 'title,body',
```

```php
declare(strict_types = 1);
namespace App\GraphQL\Types;

use App\Models\User;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class UserType extends GraphQLType
{
    protected $attributes = [
        'name'          => 'User',
        'description'   => 'A user',
        'model'         => User::class,
    ];

    public function fields(): array
    {
        return [
            'uuid' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The uuid of the user'
            ],
            'email' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The email of user'
            ],
            'profile' => [
                'type' => GraphQL::type('Profile'),
                'description' => 'The user profile',
            ],
            'posts' => [
                'type' => Type::listOf(GraphQL::type('Post')),
                'description' => 'The user posts',
                // Can also be defined as a string
                'always' => ['title', 'body'],
            ]
        ];
    }
}
```

At this point we have a profile and a post type as expected for any model:

```php
class ProfileType extends GraphQLType
{
    protected $attributes = [
        'name'          => 'Profile',
        'description'   => 'A user profile',
        'model'         => UserProfileModel::class,
    ];

    public function fields(): array
    {
        return [
            'name' => [
                'type' => Type::string(),
                'description' => 'The name of user'
            ]
        ];
    }
}
```

```php
class PostType extends GraphQLType
{
    protected $attributes = [
        'name'          => 'Post',
        'description'   => 'A post',
        'model'         => PostModel::class,
    ];

    public function fields(): array
    {
        return [
            'title' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The title of the post'
            ],
            'body' => [
                'type' => Type::string(),
                'description' => 'The body the post'
            ]
        ];
    }
}
```

### Custom Relation Queries

You can specify a `query` callback that will be applied to the Eloquent
eager-loading query for a relation:

```php
class UserType extends GraphQLType
{

    // ...

    public function fields(): array
    {
        return [
            // ...

            // Relation
            'posts' => [
                'type'          => Type::listOf(GraphQL::type('Post')),
                'description'   => 'A list of posts written by the user',
                'args'          => [
                    'date_from' => [
                        'type' => Type::string(),
                    ],
                 ],
                // $args are the local arguments passed to the relation
                // $query is the relation builder object
                // $ctx is the GraphQL context (customizable via execution middleware)
                // The return value should be the query builder or void
                'query'         => function (array $args, $query, $ctx): void {
                    $query->addSelect('some_column')
                          ->where('posts.created_at', '>', $args['date_from']);
                }
            ]
        ];
    }
}
```

### Pagination

Pagination will be used if a query or mutation returns a `PaginationType`.

Note that unless you use resolver middleware, you will have to manually supply
both the limit and page values:

```php
declare(strict_types = 1);
namespace App\GraphQL\Queries;

use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class PostsQuery extends Query
{
    public function type(): Type
    {
        return GraphQL::paginate('posts');
    }

    // ...

    public function resolve($root, array $args, $context, ResolveInfo $info, Closure $getSelectFields)
    {
        $fields = $getSelectFields();

        return Post::with($fields->getRelations())
            ->select($fields->getSelect())
            ->paginate($args['limit'], ['*'], 'page', $args['page']);
    }
}
```

Query `posts(limit:10,page:1){data{id},total,per_page}` might return:

```json
{
    "data": {
        "posts": {
            "data": [
                {"id": 3},
                {"id": 5}
            ],
            "total": 21,
            "per_page": 10
        }
    }
}
```

Note that you need to add the extra `data` object when you request paginated
resources, as the returned data gives you the paginated resources in a `data`
object at the same level as the returned pagination metadata.

#### Simple Pagination

[Simple Pagination](https://laravel.com/docs/pagination#simple-pagination)
will be used if a query or mutation returns a `SimplePaginationType`.

```php
class PostsQuery extends Query
{
    public function type(): Type
    {
        return GraphQL::simplePaginate('posts');
    }

    // ...

    public function resolve($root, array $args, $context, ResolveInfo $info, Closure $getSelectFields)
    {
        $fields = $getSelectFields();

        return Post::with($fields->getRelations())
            ->select($fields->getSelect())
            ->simplePaginate($args['limit'], ['*'], 'page', $args['page']);
    }
}
```

`SimplePaginationType` exposes the following fields: `data` (the paginated
items), `per_page`, `current_page`, `from`, `to`, and `has_more_pages`. Unlike
full pagination, `total` and `last_page` are **not** available.

#### Cursor Pagination

[Cursor Pagination](https://laravel.com/docs/pagination#cursor-pagination)
will be used if a query or mutation returns a `CursorPaginationType`.

```php
class PostsQuery extends Query
{
    public function type(): Type
    {
        return GraphQL::cursorPaginate('posts');
    }

    // ...

    public function resolve($root, array $args, $context, ResolveInfo $info, Closure $getSelectFields)
    {
        $fields = $getSelectFields();

        return Post::with($fields->getRelations())
            ->select($fields->getSelect())
            ->cursorPaginate($args['limit'], ['*'], 'cursorName', $args['cursor']);
    }
}
```

`CursorPaginationType` exposes the following fields: `data` (the paginated
items), `per_page`, `previous_cursor` (`String`, nullable), and `next_cursor`
(`String`, nullable).

#### Pagination type auto-replacement

This package automatically replaces the core pagination types with
SelectFields-aware subclasses. The subclasses implement `WrapType` (so
SelectFields can traverse into the paginated data) and mark metadata fields like
`total`, `per_page`, etc. as `selectable: false` (so they are not included in
SQL SELECT).

If you have set a custom pagination type in your config, the package will **not**
override it.

### JSON Columns

When using JSON columns in your database, the field won't be defined as a
"relationship", but rather a simple column with nested data. Use the
`is_relation` attribute to tell SelectFields not to treat it as an Eloquent
relation:

```php
class UserType extends GraphQLType
{
    // ...

    public function fields(): array
    {
        return [
            // ...

            // JSON column containing all posts made by this user
            'posts' => [
                'type'          => Type::listOf(GraphQL::type('Post')),
                'description'   => 'A list of posts written by the user',
                // Now this will simply request the "posts" column, and it won't
                // query for all the underlying columns in the "post" object
                // The value defaults to true
                'is_relation' => false
            ]
        ];
    }

    // ...
}
```

### Wrap Types

If you use SelectFields in a query that returns a
[wrap type](https://github.com/rebing/graphql-laravel#wrap-types), your wrapper
class **must** implement the `WrapType` marker interface. This tells
SelectFields to look through the wrapper's `data` field to find the underlying
model type and generate the correct `SELECT`/`WITH` clauses.

```php
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Contracts\WrapType;
use Rebing\GraphQL\Support\Facades\GraphQL;

class PostWrappedType extends ObjectType implements WrapType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'PostWrapped',
            'fields' => fn () => [
                'data' => [
                    'type' => Type::listOf(GraphQL::type('Post')),
                    'is_relation' => false,
                ],
                'message' => [
                    'type' => Type::string(),
                    'selectable' => false,
                ],
            ],
        ]);
    }
}
```

The package's pagination types already implement this interface. Custom
pagination classes configured via the `pagination_type`,
`simple_pagination_type`, or `cursor_pagination_type` config keys must also
implement it.

### Abstract Types (Unions and Interfaces)

When using SelectFields with union or interface types, custom `query` callbacks
on relation fields defined in member/concrete types are supported. SelectFields
will match the concrete type at eager-load time and apply the callback
automatically.

**Note:** When a query includes inline fragments on multiple member types that
each request different relations, SelectFields will merge all requested
relations into the eager-load set.

**Note:** For union types, SelectFields cannot determine the concrete type at
query-build time, so it uses `SELECT *` instead of selecting specific columns.

## API Reference

### `SelectFields`

```php
use Rebing\GraphQL\Support\SelectFields;

// Constructed automatically via DI - you rarely need the constructor directly
$fields = new SelectFields($parentType, $queryArgs, $ctx, $fieldsAndArguments);

// Get the columns to select
$fields->getSelect();   // array<int, string|Expression>

// Get the relations to eager-load (with constrained closures)
$fields->getRelations(); // array<string, Closure|mixed>
```

### `WrapType`

```php
use Rebing\GraphQL\Support\Contracts\WrapType;

// Marker interface - no methods to implement
class MyCustomWrapper extends ObjectType implements WrapType { ... }
```

### `SelectFieldsParameterInjector`

Implements `Rebing\GraphQL\Support\Contracts\ResolverParameterInjector`. This
is registered automatically by the service provider. You only need to interact
with it if you're building a custom SelectFields subclass:

```php
use Rebing\GraphQL\Support\Field;
use Rebing\GraphQL\Support\SelectFieldsParameterInjector;

// Registered automatically - shown here for reference
Field::registerParameterInjector(new SelectFieldsParameterInjector());
```

## Known Limitations

- Resolving fields via aliases will only resolve them once, even if the fields
  have different arguments
  ([Issue](https://github.com/rebing/graphql-laravel/issues/604)).

## License

MIT
