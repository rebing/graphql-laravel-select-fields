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
 * One type, many dedicated fields (mirrors the AlwaysTests/PostType
 * pattern) — each field below is wired for exactly ONE Task 7
 * fallback/interplay scenario so the tests stay isolated from each other:
 *
 * - 'comments': plain HasMany + 'id' arg + 'always' => 'flag' (interplay b:
 *   always-column propagation into variant subtrees).
 * - 'secret': 'selectable' => false sibling (interplay c).
 * - 'taggedComments': 'alias' => 'comments' (interplay a: alias-config
 *   relations get an injected resolver upstream and stay legacy/silent).
 * - 'privateCommentsAllowed' / 'privateCommentsDenied': 'privacy' config
 *   (interplay d: the privacy contract — unsupported by design).
 * - 'customComments': explicit 'resolve' (bullet: custom resolver stays
 *   silent).
 */
class FallbackPostType extends GraphQLType
{
    protected $attributes = [
        'name' => 'FallbackPost',
        'model' => Post::class,
    ];

    public function fields(): array
    {
        $idArg = [
            'id' => [
                'type' => Type::int(),
            ],
        ];

        $filterById = function (array $args, HasMany $query): HasMany {
            if (isset($args['id'])) {
                $query->where(DB::raw('comments.id'), '=', $args['id']);
            }

            return $query;
        };

        return [
            'id' => [
                'type' => Type::nonNull(Type::ID()),
            ],
            'secret' => [
                'type' => Type::string(),
                'alias' => 'body',
                'selectable' => false,
            ],
            'comments' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('FallbackComment')))),
                'args' => $idArg,
                'always' => 'flag',
                'query' => $filterById,
            ],
            'taggedComments' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('FallbackComment')))),
                'alias' => 'comments',
                'args' => $idArg,
                'query' => $filterById,
            ],
            'privateCommentsAllowed' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('FallbackComment')))),
                'alias' => 'comments',
                'args' => $idArg,
                'query' => $filterById,
                'privacy' => fn (): bool => true,
            ],
            'privateCommentsDenied' => [
                'type' => Type::listOf(Type::nonNull(GraphQL::type('FallbackComment'))),
                'alias' => 'comments',
                'args' => $idArg,
                'query' => $filterById,
                'privacy' => fn (): bool => false,
            ],
            'customComments' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('FallbackComment')))),
                'alias' => 'comments',
                'args' => $idArg,
                'resolve' => function (Post $root, array $args) {
                    $query = $root->comments();

                    if (isset($args['id'])) {
                        $query->where(DB::raw('comments.id'), '=', $args['id']);
                    }

                    return $query->get();
                },
            ],
        ];
    }
}
