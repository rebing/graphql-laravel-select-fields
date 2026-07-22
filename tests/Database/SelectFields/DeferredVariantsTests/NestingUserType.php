<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;
use Rebing\GraphQL\Tests\Support\Models\User;

/**
 * Grandparent of the nesting fixture graph (Task 7): plain `User::posts()`
 * (no args, no conflict) so the `comments` variant conflict lives at the
 * SECOND level, for the depth>=2 batching test.
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
            ],
        ];
    }
}
