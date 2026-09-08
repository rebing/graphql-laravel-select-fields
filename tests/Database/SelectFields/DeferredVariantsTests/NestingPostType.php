<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use GraphQL\Type\Definition\Type;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;
use Rebing\GraphQL\Tests\Support\Models\Post;

/**
 * Root of the nesting fixture graph (Task 7): `Post::comments()` (HasMany)
 * carries an `id` arg so aliasing it with different values produces the
 * outer variant conflict for the nesting/dedupe/aliased-ancestors tests.
 */
class NestingPostType extends GraphQLType
{
    protected $attributes = [
        'name' => 'NestingPost',
        'model' => Post::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::ID()),
            ],
            'comments' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('NestingComment')))),
                'args' => [
                    'id' => [
                        'type' => Type::int(),
                    ],
                    // Independent from 'id': the nesting/dedupe/aliased-ancestors
                    // tests filter by a per-row-unique 'id' (fine with few
                    // parents); the depth>=2 batching test needs a filter value
                    // that's IDENTICAL across many parent posts so they all
                    // share the same variant hash — 'flag' is that value.
                    'flag' => [
                        'type' => Type::boolean(),
                    ],
                ],
                'query' => function (array $args, HasMany $query): HasMany {
                    if (isset($args['id'])) {
                        $query->where(DB::raw('comments.id'), '=', $args['id']);
                    }

                    if (isset($args['flag'])) {
                        $query->where(DB::raw('comments.flag'), '=', $args['flag']);
                    }

                    return $query;
                },
            ],
        ];
    }
}
