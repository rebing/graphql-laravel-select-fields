<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Type as GraphQLType;
use Rebing\GraphQL\Tests\Support\Models\Comment;

/**
 * Related type for the fallback/interplay tests (Task 7): plain leaf, no
 * args of its own — every conflict in this test file lives on the PARENT
 * relation field, not here.
 */
class FallbackCommentType extends GraphQLType
{
    protected $attributes = [
        'name' => 'FallbackComment',
        'model' => Comment::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::ID()),
            ],
            'flag' => [
                'type' => Type::nonNull(Type::boolean()),
            ],
        ];
    }
}
