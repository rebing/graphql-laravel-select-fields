<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use GraphQL\Type\Definition\Type;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;
use Rebing\GraphQL\Tests\Support\Models\User;

/**
 * Grandparent of the nesting fixture graph (Task 7): `User::posts()` used
 * WITHOUT args (no conflict) keeps the `comments` variant conflict at the
 * SECOND level for the depth>=2 batching test; the optional `flag` arg
 * (unused there) lets the late-wave re-batching test alias `posts` into
 * its own variant conflict.
 */
class NestingUserType extends GraphQLType
{
    protected $attributes = [
        'name' => 'NestingUser',
        'model' => User::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::ID()),
            ],
            'posts' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('NestingPost')))),
                'args' => [
                    'flag' => [
                        'type' => Type::boolean(),
                    ],
                ],
                'query' => function (array $args, HasMany $query): HasMany {
                    if (isset($args['flag'])) {
                        $query->where(DB::raw('posts.flag'), '=', $args['flag']);
                    }

                    return $query;
                },
            ],
        ];
    }
}
