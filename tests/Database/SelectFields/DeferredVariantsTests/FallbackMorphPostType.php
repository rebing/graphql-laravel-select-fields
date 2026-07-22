<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;
use Rebing\GraphQL\Tests\Support\Models\Post;

/**
 * Concrete member of FallbackLikable, for the MorphTo fallback test.
 */
class FallbackMorphPostType extends GraphQLType
{
    protected $attributes = [
        'name' => 'FallbackMorphPost',
        'model' => Post::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::id()),
            ],
        ];
    }

    public function interfaces(): array
    {
        return [
            GraphQL::type('FallbackLikable'),
        ];
    }
}
