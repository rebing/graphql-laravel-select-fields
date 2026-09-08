<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Type as GraphQLType;
use Rebing\GraphQL\Tests\Support\Models\Like;

/**
 * Leaf of the nesting fixture graph (Task 7): `Comment::likes()` (MorphMany),
 * exposed with a `userId` arg so a nested alias conflict can be built one
 * level below the `comments` variant (deferred-inside-deferred coverage).
 */
class NestingLikeType extends GraphQLType
{
    protected $attributes = [
        'name' => 'NestingLike',
        'model' => Like::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::ID()),
            ],
        ];
    }
}
