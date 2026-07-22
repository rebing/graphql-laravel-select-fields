<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\DeferredVariantsTests;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\InterfaceType;
use Rebing\GraphQL\Tests\Support\Models\Comment;
use Rebing\GraphQL\Tests\Support\Models\Post;

/**
 * Dedicated interface for the "unsupported: MorphTo relation" fallback test
 * (`Like::likable()`) — mirrors MorphRelationshipTests\LikableInterfaceType.
 */
class FallbackLikableInterfaceType extends InterfaceType
{
    protected $attributes = [
        'name' => 'FallbackLikable',
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::id()),
            ],
        ];
    }

    public function resolveType(mixed $root): ?Type
    {
        if ($root instanceof Post) {
            return GraphQL::type('FallbackMorphPost');
        }

        if ($root instanceof Comment) {
            return GraphQL::type('FallbackMorphComment');
        }

        return null;
    }
}
