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
 * Dedicated type for the "unsupported: paginated/wrapped relation field"
 * fallback test. The 'comments' field name deliberately matches the
 * underlying `Post::comments()` relation method exactly (no 'alias', no
 * 'resolve') so that `hasCustomResolver()` stays false and `isSupported()`'s
 * wrap-type check is what actually gates the fallback — using 'alias' here
 * would (correctly, per interplay test (a)) take the silent custom-resolver
 * path instead, never reaching the wrap-type check this test targets.
 */
class FallbackWrapPostType extends GraphQLType
{
    protected $attributes = [
        'name' => 'FallbackWrapPost',
        'model' => Post::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::ID()),
            ],
            'comments' => [
                'type' => GraphQL::wrapType('FallbackWrapComment', 'FallbackWrapCommentWrapped', FallbackWrapType::class),
                'args' => [
                    'id' => [
                        'type' => Type::int(),
                    ],
                ],
                'query' => function (array $args, HasMany $query): HasMany {
                    if (isset($args['id'])) {
                        $query->where(DB::raw('comments.id'), '=', $args['id']);
                    }

                    return $query;
                },
            ],
        ];
    }
}
