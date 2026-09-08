<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;
use Rebing\GraphQL\Tests\Support\Models\Like;

/**
 * `Like::likable()` is a MorphTo relation — `likable` here carries a
 * (functionally unused) 'flag' arg purely to create the aliased args
 * conflict; MorphTo relations are unconditionally unsupported (spec §2.1a),
 * so this always takes the legacy fallback regardless of the arg's value.
 */
class FallbackLikeType extends GraphQLType
{
    protected $attributes = [
        'name' => 'FallbackLike',
        'model' => Like::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::id()),
            ],
            'likable' => [
                'type' => Type::nonNull(GraphQL::type('FallbackLikable')),
                'args' => [
                    'flag' => [
                        'type' => Type::boolean(),
                    ],
                ],
            ],
        ];
    }
}
